<?php

namespace App\Services;

use App\Models\AttendanceLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * AT2 ONLY. Collects outbound events from original dbo.Attendance only.
 * Never changes either attendance table; staged-only punches are not sendable.
 * Collecting an outbox event is NOT delivering it to ZenHR.
 */
final class ZenHrAttendanceOutbox
{
    public const CUTOFF = '2026-11-12 00:00:00';
    public const TIMEZONE = 'Asia/Dubai';

    private const TABLE = 'adms.zenhr_attendance_outbox';
    private const CURSORS = 'adms.zenhr_attendance_cursors';

    public function isProvisioned(): bool
    {
        $db = DB::connection('sqlsrv');
        $r = $db->selectOne("SELECT
            OBJECT_ID(N'adms.zenhr_attendance_outbox', N'U') AS o,
            OBJECT_ID(N'adms.zenhr_attendance_cursors', N'U') AS c");
        return $r !== null && $r->o !== null && $r->c !== null;
    }

    public function stats(): array
    {
        if (! $this->isProvisioned()) {
            return ['ready' => false, 'counts' => [], 'cursors' => null];
        }

        $db = DB::connection('sqlsrv');
        return [
            'ready' => true,
            'counts' => $db->table(self::TABLE)->select('delivery_state')
                ->selectRaw('COUNT_BIG(*) AS count')
                ->groupBy('delivery_state')->pluck('count', 'delivery_state')->all(),
            'cursors' => $db->table(self::CURSORS)->where('id', 1)
                ->first(['last_dbo_id', 'last_adms_id', 'updated_at']),
        ];
    }

    /**
     * The default call is DRY RUN. --apply must be explicitly provided to
     * insert into the dedicated outbox; no request to ZenHR is sent.
     *
     * Both source cursors are advanced only AFTER the outbox INSERTs commit.
     */
    public function collect(int $limit = 500, bool $apply = false): array
    {
        if (! in_array(config('adms_instance.role'), ['console', 'dual'], true)
            || ! config('zenhr.enabled', false)
            || strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)) !== 'at2.superfix.ae') {
            throw new RuntimeException('ZenHR outbox collector is allowed only on configured AT2 console.');
        }

        if (! $this->isProvisioned()) {
            throw new RuntimeException('Run DBA script docs/sql/adms-zenhr-attendance-outbox.sql first.');
        }

        $limit = max(1, min(1000, $limit));
        $db = DB::connection('sqlsrv');
        $cursor = $db->table(self::CURSORS)->where('id', 1)->first();
        if (! $cursor) {
            throw new RuntimeException('Outbox cursor row is missing; run the idempotent DBA script.');
        }

        $dbCursor = (int) $cursor->last_dbo_id;
        $admsCursor = (int) $cursor->last_adms_id;
        $cutoffLocal = trim((string) config('zenhr.outbox_cutoff_local', self::CUTOFF));
        if (! preg_match('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/', $cutoffLocal)) {
            throw new RuntimeException('Invalid ZENHR_OUTBOX_CUTOFF_LOCAL date format.');
        }
        $cutoff = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $cutoffLocal, self::TIMEZONE);
        if ($cutoff === false || $cutoff->format('Y-m-d H:i:s') !== $cutoffLocal) {
            throw new RuntimeException('Invalid ZENHR_OUTBOX_CUTOFF_LOCAL date.');
        }
        // Original dbo.Attendance stores device-local UAE wall-clock times.

        $original = DB::connection('legacy_sqlsrv')->table('dbo.Attendance')
            ->where('Id', '>', $dbCursor)
            ->where('VerifyTime', '>=', $cutoffLocal)
            ->orderBy('Id')
            ->limit($limit)
            ->get(['Id', 'BadgeNumber', 'DeviceSerialNumber', 'VerifyTime', 'Status', 'VerifyType']);

        // Authoritative-ledger policy: the outbound outbox collects only
        // records committed to original dbo.Attendance. Staged-only punches
        // are NOT eligible for ZenHR, even when the staging record is valid.
        $staged = collect();

        $deviceSerials = $staged->pluck('device_id')->filter()->unique()->values()->all();
        $serials = $deviceSerials === [] ? collect()
            : DB::connection('sqlsrv')->table('adms.devices')
                ->whereIn('id', $deviceSerials)->pluck('serial_number', 'id');

        $new = [];
        $proposed = 0;
        $duplicate = 0;
        $needsReview = 0;
        $newDboCursor = $dbCursor;
        $newAdmsCursor = $admsCursor;
        $originalPromotions = [];
        $seen = [];

        foreach ($original as $row) {
            $newDboCursor = max($newDboCursor, (int) $row->Id);
            $item = $this->build(
                'dbo', (int) $row->Id,
                (string) ($row->BadgeNumber ?? ''),
                (string) ($row->DeviceSerialNumber ?? ''),
                CarbonImmutable::parse((string) $row->VerifyTime, self::TIMEZONE)->format('Y-m-d H:i:s'),
                (string) ($row->Status ?? ''),
                (string) ($row->VerifyType ?? '')
            );
            $originalPromotions[] = $item;
            $this->offer($item, $seen, $new, $duplicate, $needsReview, $proposed);
        }

        foreach ($staged as $row) {
            $newAdmsCursor = max($newAdmsCursor, (int) $row->id);
            $status = 'unknown';
            try {
                $status = LegacyAttendanceValueMapper::status([
                    'raw_payload' => $row->raw_payload,
                    'log_type' => $row->log_type,
                ]);
            } catch (\Throwable) {
                // An unrecognized function is retained as needs_review.
            }
            $serial = trim((string) ($serials->get($row->device_id) ?? ''));
            $local = CarbonImmutable::instance($row->log_time)
                ->setTimezone(self::TIMEZONE)->format('Y-m-d H:i:s');
            $item = $this->build(
                'adms', (int) $row->id, (string) ($row->employee_code ?? ''),
                $serial, $local, $status, (string) ($row->verify_type ?? '')
            );
            $this->offer($item, $seen, $new, $duplicate, $needsReview, $proposed);
        }

        if ($apply) {
            $db->transaction(function () use ($db, $new, $newDboCursor, $newAdmsCursor, $originalPromotions): void {
                // Older staging-only outbox events may already exist. Promote
                // their *source identity* only after finding the matching
                // authoritative dbo attendance event. Never reset delivery
                // state, retry sent/unknown/inflight records, or change history.
                foreach ($originalPromotions as $item) {
                    $db->table(self::TABLE)
                        ->where('event_key', $item['event_key'])
                        ->where('source_kind', 'adms')
                        ->whereIn('delivery_state', ['pending', 'needs_review'])
                        ->where('attempts', 0)
                        ->whereNull('last_attempt_at')
                        ->update([
                            'source_kind' => 'dbo',
                            'source_id' => $item['source_id'],
                            'device_serial' => $item['device_serial'],
                            'direction' => $item['direction'],
                            'verify_type' => $item['verify_type'],
                            'updated_at' => now('UTC')->format('Y-m-d H:i:s'),
                        ]);
                }
                foreach ($new as $row) {
                    // Cross-source events collide on event_key, intentionally.
                    if (! $db->table(self::TABLE)->where('event_key', $row['event_key'])->exists()) {
                        $db->table(self::TABLE)->insert($row);
                    }
                }
                $db->table(self::CURSORS)->where('id', 1)->update([
                    'last_dbo_id' => $newDboCursor,
                    'last_adms_id' => $newAdmsCursor,
                    'updated_at' => now('UTC')->format('Y-m-d H:i:s'),
                ]);
            }, 1);
        }

        return [
            'mode' => $apply ? 'STAGED IN OUTBOX · ZENHR UNSENT' : 'DRY RUN · NO WRITES',
            'dbo_read' => $original->count(),
            'adms_read' => $staged->count(),
            'candidate_unique' => $proposed,
            'duplicate_found' => $duplicate,
            'needs_review' => $needsReview,
            'source_cursor_dbo' => $newDboCursor,
            'source_cursor_adms' => $newAdmsCursor,
        ];
    }

    /**
     * One-time retrospective scan independent of already advanced outbox cursors.
     * Read original dbo + ADMS staging from the chosen cutoff up to the
     * pre-cutover review boundary. Inserts are quarantined as needs_review
     * by build(); this method NEVER posts to ZenHR and NEVER updates cursors.
     */
    public function backfillPreCutover(int $limit = 500, int $afterDboId = 0, int $afterAdmsId = 0, bool $apply = false): array
    {
        if (! $this->isProvisioned()) {
            throw new RuntimeException('ZenHR outbox tables are not provisioned.');
        }
        if ($limit < 1 || $limit > 1000 || $afterDboId < 0 || $afterAdmsId < 0) {
            throw new RuntimeException('Invalid history backfill pagination.');
        }
        $start = trim((string) config('zenhr.outbox_cutoff_local', self::CUTOFF));
        $end = trim((string) config('zenhr.attendance_review_before', self::CUTOFF));
        foreach ([$start, $end] as $value) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
                throw new RuntimeException('History backfill boundary must be YYYY-MM-DD HH:MM:SS.');
            }
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $value, self::TIMEZONE);
            if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $value) {
                throw new RuntimeException('Invalid history backfill date.');
            }
        }
        if ($start >= $end) {
            throw new RuntimeException('History start must precede pre-cutover review boundary.');
        }

        $original = DB::connection('legacy_sqlsrv')->table('dbo.Attendance')
            ->where('Id', '>', $afterDboId)
            ->where('VerifyTime', '>=', $start)
            ->where('VerifyTime', '<', $end)
            ->orderBy('Id')->limit($limit)
            ->get(['Id', 'BadgeNumber', 'DeviceSerialNumber', 'VerifyTime', 'Status', 'VerifyType']);

        $startUtc = CarbonImmutable::parse($start, self::TIMEZONE)->utc()->format('Y-m-d H:i:s');
        $endUtc = CarbonImmutable::parse($end, self::TIMEZONE)->utc()->format('Y-m-d H:i:s');
        $staged = AttendanceLog::query()
            ->where('id', '>', $afterAdmsId)
            ->where('log_time', '>=', $startUtc)
            ->where('log_time', '<', $endUtc)
            ->orderBy('id')->limit($limit)
            ->get(['id', 'device_id', 'employee_code', 'log_time', 'raw_payload', 'log_type', 'verify_type']);

        $ids = $staged->pluck('device_id')->filter()->unique()->values()->all();
        $serials = $ids === [] ? collect()
            : DB::connection('sqlsrv')->table('adms.devices')
                ->whereIn('id', $ids)->pluck('serial_number', 'id');

        $seen = [];
        $new = [];
        $duplicate = 0;
        $review = 0;
        $proposed = 0;
        $lastDbo = $afterDboId;
        $lastAdms = $afterAdmsId;
        foreach ($original as $row) {
            $lastDbo = max($lastDbo, (int) $row->Id);
            $item = $this->build(
                'dbo', (int) $row->Id, (string) ($row->BadgeNumber ?? ''),
                (string) ($row->DeviceSerialNumber ?? ''),
                CarbonImmutable::parse((string) $row->VerifyTime, self::TIMEZONE)->format('Y-m-d H:i:s'),
                (string) ($row->Status ?? ''), (string) ($row->VerifyType ?? '')
            );
            $this->offer($item, $seen, $new, $duplicate, $review, $proposed);
        }
        foreach ($staged as $row) {
            $lastAdms = max($lastAdms, (int) $row->id);
            $direction = 'unknown';
            try {
                $direction = LegacyAttendanceValueMapper::status([
                    'raw_payload' => $row->raw_payload,
                    'log_type' => $row->log_type,
                ]);
            } catch (\Throwable) {
                // Unknown device direction is recorded and ZenHR decides In/Out.
            }
            $item = $this->build(
                'adms', (int) $row->id, (string) ($row->employee_code ?? ''),
                (string) ($serials->get($row->device_id) ?? ''),
                CarbonImmutable::instance($row->log_time)
                    ->setTimezone(self::TIMEZONE)->format('Y-m-d H:i:s'),
                $direction, (string) ($row->verify_type ?? '')
            );
            $this->offer($item, $seen, $new, $duplicate, $review, $proposed);
        }

        if ($apply && $new !== []) {
            DB::connection('sqlsrv')->transaction(function () use ($new): void {
                $db = DB::connection('sqlsrv');
                foreach ($new as $row) {
                    if ($row['delivery_state'] !== 'needs_review') {
                        throw new RuntimeException('Unsafe pre-cutover event is not quarantined.');
                    }
                    if (! $db->table(self::TABLE)->where('event_key', $row['event_key'])->exists()) {
                        $db->table(self::TABLE)->insert($row);
                    }
                }
            }, 1);
        }

        return [
            'mode' => $apply ? 'STAGED FOR REVIEW · NO ZENHR POST' : 'DRY RUN · NO WRITES',
            'range_local' => $start.' through '.$end.' (exclusive)',
            'dbo_read' => $original->count(),
            'adms_read' => $staged->count(),
            'unique_new' => $proposed,
            'already_present' => $duplicate,
            'needs_review' => $review,
            'after_dbo_id' => $lastDbo,
            'after_adms_id' => $lastAdms,
            'has_more' => $original->count() === $limit || $staged->count() === $limit,
        ];
    }

    private function offer(
        array $item,
        array &$seen,
        array &$new,
        int &$duplicate,
        int &$review,
        int &$proposed
    ): void {
        // An employee's identical timestamp is ONE punch even if two
        // different terminals or different ZKTeco In/Out states reported it.
        $identity = ZenHrPunchIdentity::key(
            (string) $item['badge_number'], (string) $item['punched_at_local']
        );
        $key = $identity ?? $item['event_key'];
        $alreadyStored = DB::connection('sqlsrv')->table(self::TABLE)
            ->where(function ($q) use ($item, $identity): void {
                $q->where('event_key', $item['event_key']);
                if ($identity !== null) {
                    // Also catch old v1 outbox rows that used device/direction keys.
                    $q->orWhere(function ($same) use ($item): void {
                        $same->where('badge_number', $item['badge_number'])
                            ->where('punched_at_local', $item['punched_at_local']);
                    });
                }
            })->exists();

        if (isset($seen[$key]) || $alreadyStored) {
            $duplicate++;
            return;
        }

        $seen[$key] = true;
        $new[] = $item;
        $proposed++;
        if ($item['delivery_state'] === 'needs_review') {
            $review++;
        }
    }

    private function build(
        string $source, int $id, string $badge, string $serial,
        string $time, string $direction, string $verify
    ): array {
        $badge = trim($badge);
        $serial = trim($serial);
        $direction = strtolower(trim($direction));
        $isHiliUncorrected = $serial === 'CEG9223661162'
            && $time < '2026-11-12 17:10:00';

        if ($badge === '') $badge = '[missing badge]';
        if ($serial === '') $serial = '[missing serial]';
        if ($direction === '') $direction = 'unknown';
        // ZenHR owns clock-in/out interpretation. A terminal's unknown
        // In/Out status is not by itself a reason to suppress a punch.
        // Keep unknown direction in the outbox for forensic visibility.
        $reviewBefore = trim((string) config('zenhr.attendance_review_before', self::CUTOFF));
        // Pre-cutover records may already have been exported by the original
        // BioWeb sender. Never automatically re-POST unverified history.
        $previouslyExportable = $reviewBefore !== '' && $time < $reviewBefore;
        $needsReview = str_starts_with($badge, '[')
            || str_starts_with($serial, '[') || $isHiliUncorrected || $previouslyExportable;

        // Canonical event key for new punches, independent of the device
        // and its In/Out label. Fallback retains unknown-badge diagnostics.
        $key = ZenHrPunchIdentity::key($badge, $time)
            ?? hash('sha256', implode('|', ['adms-zenhr-v1', $serial, $badge, $time, $direction]));
        $now = now('UTC')->format('Y-m-d H:i:s');

        return [
            'event_key' => $key,
            'source_kind' => $source,
            'source_id' => $id,
            'badge_number' => mb_substr($badge, 0, 80),
            'device_serial' => mb_substr($serial, 0, 64),
            'punched_at_local' => $time,
            'direction' => mb_substr($direction, 0, 24),
            'verify_type' => mb_substr($verify, 0, 32),
            'delivery_state' => $needsReview ? 'needs_review' : 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
