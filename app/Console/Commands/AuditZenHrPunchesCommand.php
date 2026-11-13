<?php

namespace App\Console\Commands;

use App\Services\ZenHrOAuthService;
use App\Services\ZenHrPunchIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Read-only safety audit: neither this command nor its remote GET calls
 * writes an attendance record. No physical terminal/branch is matched.
 */
final class AuditZenHrPunchesCommand extends Command
{
    protected $signature = 'adms:zenhr-preflight
        {--remote : Compare employee/date to ZenHR existing attendance records (GET only)}
        {--limit=1000 : Local unsent rows to inspect (1-5000)}';

    protected $description = 'Audit raw attendance punches without using device or In/Out state; no ZenHR POST';

    public function handle(ZenHrOAuthService $oauth): int
    {
        $role = (string) config('adms_instance.role');
        if (! in_array($role, ['dual', 'console'], true)
            || ! config('zenhr.enabled')
            || strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)) !== 'at2.superfix.ae') {
            $this->error('AT2 console configuration required. No changes made.');
            return self::FAILURE;
        }

        try {
            $db = DB::connection('sqlsrv');
            $limit = max(1, min(5000, (int) $this->option('limit')));
            $base = $db->table('adms.zenhr_attendance_outbox')
                ->whereIn('delivery_state', ['pending', 'needs_review']);
            $total = (clone $base)->count();
            $rows = $base->orderBy('punched_at_local')->orderBy('event_key')->limit($limit)
                ->get(['event_key', 'badge_number', 'punched_at_local', 'device_serial', 'direction', 'delivery_state']);

            $unique = [];
            $invalid = 0;
            foreach ($rows as $row) {
                $at = substr((string) $row->punched_at_local, 0, 19);
                $key = ZenHrPunchIdentity::key((string) $row->badge_number, $at);
                if ($key === null) {
                    $invalid++;
                    continue;
                }
                if (! isset($unique[$key])) {
                    $unique[$key] = [
                        'badge' => trim((string) $row->badge_number),
                        'local' => $at,
                        'blocked' => false,
                        'count' => 0,
                        'devices' => [],
                    ];
                }
                $unique[$key]['count']++;
                $unique[$key]['blocked'] = $unique[$key]['blocked'] || $row->delivery_state === 'needs_review';
                $unique[$key]['devices'][(string) $row->device_serial] = true;
            }

            $held = 0;
            $duplicates = 0;
            $crossDevice = 0;
            $candidates = [];
            foreach ($unique as $event) {
                $duplicates += $event['count'] - 1;
                if (count($event['devices']) > 1) $crossDevice++;
                if ($event['blocked']) {
                    $held++;
                } else {
                    $candidates[] = $event;
                }
            }

            $this->table(['Local outbox preflight', 'Result'], [
                ['Pending/review rows', $total],
                ['Rows inspected', count($rows)],
                ['Unique employee + exact local timestamp', count($unique)],
                ['Duplicate punches regardless of terminal/direction', $duplicates],
                ['Cross-device duplicate times', $crossDevice],
                ['Held employee/time groups', $held],
                ['Invalid badge/time rows', $invalid],
                ['Not-held unique candidates (NOT authorized to POST)', count($candidates)],
                ['Scan complete', $total <= $limit ? 'YES' : 'NO · raise --limit'],
            ]);

            if ($total > $limit) {
                $this->warn('Incomplete local scan; remote comparison not allowed.');
                return self::FAILURE;
            }
            if (! $this->option('remote') || $candidates === []) {
                $this->warn('No ZenHR attendance POST. Existing In/Out values are ignored.');
                return self::SUCCESS;
            }

            $branchId = (int) config('zenhr.attendance_branch_id', 0);
            if ($branchId <= 0) {
                throw new RuntimeException('Configure ZENHR_ATTENDANCE_BRANCH_ID first.');
            }

            $earliest = min(array_column($candidates, 'local'));
            $latest = max(array_column($candidates, 'local'));
            $from = CarbonImmutable::parse(substr($earliest, 0, 10), 'Asia/Dubai')->subDay()->toDateString();
            $to = CarbonImmutable::parse(substr($latest, 0, 10), 'Asia/Dubai')->addDay()->toDateString();
            $url = rtrim((string) config('zenhr.api_base'), '/').'/branches/'.$branchId.'/attendance_records';
            $token = $oauth->getUsableAccessToken();
            $data = [];
            $totalRemote = null;

            for ($page = 1; $page <= 100; $page++) {
                $response = Http::withToken($token)->acceptJson()->timeout(30)->get($url, [
                    'page' => $page,
                    'limit' => 100,
                    'filter[attendance_date][from]' => $from,
                    'filter[attendance_date][to]' => $to,
                ]);
                if (! $response->successful()) {
                    throw new RuntimeException('ZenHR GET failed on page '.$page.' (HTTP '.$response->status().').');
                }
                $json = $response->json();
                if (! is_array($json) || ! is_array($json['data'] ?? null)
                    || ! is_numeric($json['pagination']['total_entries'] ?? null)) {
                    throw new RuntimeException('Unexpected ZenHR response shape; audit stopped.');
                }
                $totalRemote ??= (int) $json['pagination']['total_entries'];
                if ($totalRemote !== (int) $json['pagination']['total_entries']) {
                    throw new RuntimeException('ZenHR total changed during pagination; rerun.');
                }
                $pages = max(1, (int) ($json['pagination']['total_pages']
                    ?? ceil($totalRemote / max(1, count($json['data'])))));
                if ($pages > 100) {
                    throw new RuntimeException('ZenHR requires more than 100 pages; audit stopped.');
                }
                $data = array_merge($data, $json['data']);
                if ($page >= $pages) break;
                // Respect production rate limit: 15 calls per 4 seconds.
                usleep(350000);
            }
            if (count($data) < $totalRemote) {
                throw new RuntimeException('Incomplete ZenHR pagination; no record cleared.');
            }

            $index = [];
            $missingIdentity = 0;
            foreach ($data as $record) {
                $date = substr((string) ($record['attendance_date'] ?? ''), 0, 10);
                $numbers = array_unique(array_filter(array_map('trim', [
                    (string) ($record['card_id'] ?? ''),
                    (string) ($record['employee']['employment_number'] ?? ''),
                ]), static fn (string $x): bool => $x !== ''));
                if ($date === '' || $numbers === []) {
                    $missingIdentity++;
                    continue;
                }
                foreach ($numbers as $number) {
                    $index[$number.'|'.$date][] = $record;
                }
            }

            $possible = 0;
            $matchedSummaryTime = 0;
            $noDay = 0;
            foreach ($candidates as $event) {
                $date = CarbonImmutable::parse(substr($event['local'], 0, 10), 'Asia/Dubai');
                $records = [];
                foreach ([-1, 0, 1] as $days) {
                    $records = array_merge($records, $index[$event['badge'].'|'.$date->addDays($days)->toDateString()] ?? []);
                }
                if ($records === []) {
                    $noDay++;
                    continue;
                }
                $possible++;
                $clock = substr($event['local'], 11, 8);
                foreach ($records as $record) {
                    $times = array_map('trim', [
                        (string) ($record['entry_time'] ?? ''),
                        (string) ($record['exit_time'] ?? ''),
                        (string) ($record['first_in'] ?? ''),
                        (string) ($record['last_out'] ?? ''),
                    ]);
                    if (in_array($clock, $times, true) || in_array($event['local'], $times, true)) {
                        $matchedSummaryTime++;
                        break;
                    }
                }
            }

            $this->table(['ZenHR existing records (GET only)', 'Result'], [
                ['Branch ID', $branchId],
                ['Dates scanned (extra day for night shifts)', $from.' to '.$to],
                ['ZenHR records loaded', count($data)],
                ['ZenHR rows missing card AND employee number', $missingIdentity],
                ['Candidate events with same employee/attendance date', $possible],
                ['Of these, exact summary time matches', $matchedSummaryTime],
                ['Candidates with no visible employee/date match', $noDay],
                ['Events authorized to POST automatically', 'NONE · vendor body/raw punch contract unverified'],
            ]);
            $this->warn('No ZenHR POST; same-day match is not proof of duplicate and a missing match is NOT proof of safety.');
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('ZenHR preflight halted: '.$exception->getMessage());
            return self::FAILURE;
        }
    }
}
