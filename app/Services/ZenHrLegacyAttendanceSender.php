<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Manual-only pilot of the old BioWeb ZenHR bulk integration.
 *
 * Confirmed by inspection of user-supplied CustomizedService.dll:
 * - BadgeNumber == ZenHR employment_number => ZenHR employee.id
 * - request_type == int.Parse(dbo.Attendance.StatusId)
 * - request_time == local VerifyTime + timezone offset (+0400)
 * - attendance_transaction_id == MySettings.AttendanceId
 * - added_by == 2; bulk batches up to 10; HTTP 202 means accepted
 *
 * Does not touch dbo.Attendance.Exported or legacy service settings.
 * No unattended delivery in this version.
 */
final class ZenHrLegacyAttendanceSender
{
    private const OUTBOX = 'adms.zenhr_attendance_outbox';
    private const MAX_LIMIT = 10;

    public function preview(int $limit = 1): array
    {
        $this->assertRole();
        $branch = (int) config('zenhr.attendance_branch_id', 0);
        if ($branch !== 17787) {
            throw new RuntimeException('Unexpected ZenHR branch; expected verified Superfix Group 17787.');
        }

        $rows = DB::connection('sqlsrv')->table(self::OUTBOX)
            ->where('delivery_state', 'pending')
            ->orderBy('punched_at_local')
            ->orderBy('event_key')
            ->limit(5000)
            ->get([
                'event_key', 'source_kind', 'source_id', 'badge_number',
                'punched_at_local', 'direction', 'device_serial',
            ]);

        $employees = $this->employeeIds();
        $valid = [];
        $unmatched = 0;
        $invalidStatus = 0;
        $invalidTime = 0;
        $ambiguous = 0;

        foreach ($rows as $r) {
            $badge = trim((string) $r->badge_number);
            $ids = $employees[$badge] ?? [];
            if (count($ids) === 0) {
                $unmatched++;
                continue;
            }
            if (count($ids) !== 1) {
                $ambiguous++;
                continue;
            }

            $raw = $this->oldStatusId($r);
            if ($raw === null) {
                $invalidStatus++;
                continue;
            }

            $time = substr((string) $r->punched_at_local, 0, 19);
            if (ZenHrPunchIdentity::key($badge, $time) === null) {
                $invalidTime++;
                continue;
            }

            $valid[] = [
                'event_key' => (string) $r->event_key,
                'employee_id' => (int) $ids[0],
                'request_time' => CarbonImmutable::createFromFormat(
                    '!Y-m-d H:i:s', $time, 'Asia/Dubai'
                )->format('Y-m-d H:i:s O'),
                // Do not alternate; preserve original BioWeb StatusId.
                'request_type' => $raw,
            ];
        }

        $count = max(1, min(self::MAX_LIMIT, $limit));
        return [
            'total_pending' => $rows->count(),
            'eligible' => count($valid),
            'no_employee_mapping' => $unmatched,
            'ambiguous_employee' => $ambiguous,
            'missing_or_non_binary_legacy_status' => $invalidStatus,
            'invalid_local_time' => $invalidTime,
            'selected' => array_slice($valid, 0, $count),
            'scan_incomplete' => $rows->count() === 5000,
        ];
    }

    /**
     * Sends only selected events. Atomic state claim blocks simultaneous
     * sends by two workers. Unknown outcomes are NEVER blindly retried.
     */
    public function send(array $selected): array
    {
        $this->assertRole();

        if (! config('zenhr.attendance_post_enabled', false)) {
            throw new RuntimeException('ZENHR_ATTENDANCE_POST_ENABLED is false; no POST sent.');
        }
        $transaction = (int) config('zenhr.attendance_transaction_id', 0);
        if ($transaction <= 0) {
            throw new RuntimeException('Set old BioWeb MySettings.AttendanceId as ZENHR_ATTENDANCE_TRANSACTION_ID.');
        }
        if (count($selected) < 1 || count($selected) > self::MAX_LIMIT) {
            throw new RuntimeException('Send limit must be between 1 and 10.');
        }

        $keys = [];
        $details = [];
        foreach ($selected as $r) {
            if (! is_array($r) || ! is_string($r['event_key'] ?? null)) {
                throw new RuntimeException('Invalid selected event.');
            }
            $keys[] = $r['event_key'];
            $details[] = [
                'employee_id' => (int) $r['employee_id'],
                'request_time' => (string) $r['request_time'],
                'request_type' => (int) $r['request_type'],
                'attendance_transaction_id' => $transaction,
                'added_by' => 2,
            ];
        }
        if (count(array_unique($keys)) !== count($keys)) {
            throw new RuntimeException('Duplicate event key in requested batch.');
        }

        // Claim the entire batch atomically: zero POST if any row changed.
        $claimed = DB::connection('sqlsrv')->transaction(function () use ($keys): bool {
            $db = DB::connection('sqlsrv');
            $current = $db->table(self::OUTBOX)->whereIn('event_key', $keys)
                ->lockForUpdate()->get(['event_key', 'delivery_state']);
            if ($current->count() !== count($keys) ||
                $current->contains(fn ($r) => $r->delivery_state !== 'pending')) {
                return false;
            }
            $affected = $db->table(self::OUTBOX)->whereIn('event_key', $keys)
                ->where('delivery_state', 'pending')->update([
                    'delivery_state' => 'inflight',
                    'last_attempt_at' => now('UTC')->format('Y-m-d H:i:s'),
                    'updated_at' => now('UTC')->format('Y-m-d H:i:s'),
                ]);
            return $affected === count($keys);
        }, 1);

        if (! $claimed) {
            throw new RuntimeException('Another process already claimed a punch; no POST sent.');
        }

        $db = DB::connection('sqlsrv');
        try {
            $token = app(ZenHrOAuthService::class)->getUsableAccessToken();
            $endpoint = (string) config('zenhr.attendance_bulk_url');
            if ($endpoint !== 'https://aiapi.zenhr.com/api/v3/branches/17787/attendance_details/bulk_insert') {
                throw new RuntimeException('Unverified ZenHR bulk endpoint.');
            }

            $response = Http::withToken($token)->acceptJson()->asJson()
                ->timeout(30)->post($endpoint, ['attendance_details' => $details]);

            $status = $response->status();
            $accepted = $status === 202;
            $db->table(self::OUTBOX)->whereIn('event_key', $keys)
                ->where('delivery_state', 'inflight')->update([
                    'delivery_state' => $accepted ? 'sent' : 'unknown',
                    'attempts' => DB::raw('attempts + 1'),
                    'last_http_status' => $status,
                    'last_error_code' => $accepted ? null : 'ZENHR_NON_202',
                    'delivered_at' => $accepted ? now('UTC')->format('Y-m-d H:i:s') : null,
                    'updated_at' => now('UTC')->format('Y-m-d H:i:s'),
                ]);

            // Never output vendor response JSON, credentials or employee names.
            return [
                'http_status' => $status,
                'accepted' => $accepted,
                'submitted' => count($details),
                'outbox_state' => $accepted ? 'sent' : 'unknown',
            ];
        } catch (\Throwable $e) {
            // An HTTP timeout may mean ZenHR accepted the request. Hold,
            // require reconciliation instead of retrying potentially twice.
            $db->table(self::OUTBOX)->whereIn('event_key', $keys)
                ->where('delivery_state', 'inflight')->update([
                    'delivery_state' => 'unknown',
                    'attempts' => DB::raw('attempts + 1'),
                    'last_error_code' => 'POST_OR_TOKEN_UNCERTAIN',
                    'updated_at' => now('UTC')->format('Y-m-d H:i:s'),
                ]);

            return [
                'accepted' => false,
                'submitted' => count($details),
                'outbox_state' => 'unknown',
                'reason' => 'Delivery could not be confirmed; no automatic retry.',
            ];
        }
    }

    private function assertRole(): void
    {
        if (! in_array(config('adms_instance.role'), ['console', 'dual'], true)
            || ! config('zenhr.enabled')
            || strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)) !== 'at2.superfix.ae') {
            throw new RuntimeException('ZenHR operations allowed only from the AT2 console.');
        }
    }

    /** ZenHR's own employee list is the only source of identity mapping. */
    private function employeeIds(): array
    {
        $token = app(ZenHrOAuthService::class)->getUsableAccessToken();
        $url = rtrim((string) config('zenhr.api_base'), '/').'/branches/17787/employees';
        $map = [];
        $total = null;
        $totalPages = null;
        $received = 0;

        for ($page = 1; $page <= 100; $page++) {
            if ($page > 1) usleep(350000);
            $response = Http::withToken($token)->acceptJson()->timeout(25)
                ->get($url, ['page' => $page, 'limit' => 100]);
            if (! $response->successful()) {
                throw new RuntimeException('ZenHR employee GET returned HTTP '.$response->status().'.');
            }

            $json = $response->json();
            if (! is_array($json) || ! is_array($json['data'] ?? null)
                || ! is_numeric($json['pagination']['total_entries'] ?? null)) {
                throw new RuntimeException('Invalid ZenHR employee response.');
            }

            $newTotal = (int) $json['pagination']['total_entries'];
            if ($total !== null && $total !== $newTotal) {
                throw new RuntimeException('ZenHR employee list changed during pagination.');
            }
            $total = $newTotal;
            $totalPages ??= max(1, (int) ($json['pagination']['total_pages'] ?? ceil($newTotal / 100)));
            if ($totalPages > 100) {
                throw new RuntimeException('ZenHR employee pagination exceeds 100 pages.');
            }

            foreach ($json['data'] as $employee) {
                if (! is_array($employee)) continue;
                $number = ZenHrRecordValues::scalar($employee['employment_number'] ?? null);
                $id = filter_var($employee['id'] ?? null, FILTER_VALIDATE_INT);
                if ($number === null || $id === false || $id < 1) continue;
                $map[$number][(int) $id] = true;
                $received++;
            }
            if ($page >= $totalPages) break;
        }
        if ($total === null || $received !== $total) {
            throw new RuntimeException('Incomplete employee list; no attendance authorized.');
        }

        return array_map(static fn (array $ids): array => array_keys($ids), $map);
    }

    /** Original BioWeb uses dbo.Attendance.StatusId, never Status text. */
    private function oldStatusId(object $item): ?int
    {
        $db = DB::connection('legacy_sqlsrv')->table('dbo.Attendance');

        if ((string) $item->source_kind === 'dbo') {
            $records = $db->where('Id', (int) $item->source_id)
                ->get(['BadgeNumber', 'VerifyTime', 'StatusId']);
        } else {
            $records = $db->where('BadgeNumber', (string) $item->badge_number)
                ->where('VerifyTime', substr((string) $item->punched_at_local, 0, 19))
                ->limit(10)->get(['BadgeNumber', 'VerifyTime', 'StatusId']);
        }
        if ($records->isEmpty()) return null;
        $states = [];
        foreach ($records as $r) {
            if (trim((string) $r->BadgeNumber) !== trim((string) $item->badge_number)
                || substr((string) $r->VerifyTime, 0, 19) !== substr((string) $item->punched_at_local, 0, 19)) {
                return null;
            }
            $s = trim((string) ($r->StatusId ?? ''));
            if ($s !== '0' && $s !== '1') return null;
            $states[$s] = (int) $s;
        }
        return count($states) === 1 ? current($states) : null;
    }
}
