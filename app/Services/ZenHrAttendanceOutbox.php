<?php

namespace App\Services;

use App\Models\AttendanceLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * AT2 ONLY. Reads the existing original SQL and ADMS staging, never changes
 * either attendance table. Deduplicates the two copies of the same punch.
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
        if (! in_array(config('adms_instance.role'), ['console'], true)
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
        $cutoff = CarbonImmutable::parse(self::CUTOFF, self::TIMEZONE);
        $utcStart = $cutoff->utc()->format('Y-m-d H:i:s');

        $original = DB::connection('legacy_sqlsrv')->table('dbo.Attendance')
            ->where('Id', '>', $dbCursor)
            ->where('VerifyTime', '>=', self::CUTOFF)
            ->orderBy('Id')
            ->limit($limit)
            ->get(['Id', 'BadgeNumber', 'DeviceSerialNumber', 'VerifyTime', 'Status', 'VerifyType']);

        $staged = AttendanceLog::query()
            ->where('id', '>', $admsCursor)
            ->where('log_time', '>=', $utcStart)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'device_id', 'employee_code', 'log_time', 'raw_payload', 'log_type', 'verify_type']);

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
            $db->transaction(function () use ($db, $new, $newDboCursor, $newAdmsCursor): void {
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

    private function offer(
        array $item,
        array &$seen,
        array &$new,
        int &$duplicate,
        int &$review,
        int &$proposed
    ): void {
        $key = $item['event_key'];
        if (isset($seen[$key])
            || DB::connection('sqlsrv')->table(self::TABLE)->where('event_key', $key)->exists()) {
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
        $allowed = ['check-in', 'check-out', 'break-out', 'break-in', 'overtime-in', 'overtime-out'];
        $isKnown = in_array($direction, $allowed, true);
        $isHiliUncorrected = $serial === 'CEG9223661162'
            && $time < '2026-11-12 17:10:00';

        if ($badge === '') $badge = '[missing badge]';
        if ($serial === '') $serial = '[missing serial]';
        if ($direction === '') $direction = 'unknown';
        $needsReview = ! $isKnown || str_starts_with($badge, '[')
            || str_starts_with($serial, '[') || $isHiliUncorrected;

        $key = hash('sha256', implode('|', ['adms-zenhr-v1', $serial, $badge, $time, $direction]));
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
