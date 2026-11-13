<?php

namespace App\Console\Commands;

use App\Services\ZenHrOAuthService;
use App\Services\ZenHrRecordValues;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Read-only audit. ZenHR POST, local identity writes, and direction guesses
 * are deliberately excluded. Employee numbers retain leading zeros.
 */
final class CheckZenHrEmployeeMatchesCommand extends Command
{
    protected $signature = 'adms:zenhr-employee-match';
    protected $description = 'AT2-only read-only ZenHR employee-ID match audit';

    public function handle(ZenHrOAuthService $oauth): int
    {
        if (! in_array((string) config('adms_instance.role'), ['dual', 'console'], true)
            || ! config('zenhr.enabled')
            || strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)) !== 'at2.superfix.ae') {
            $this->error('AT2 console only.');
            return self::FAILURE;
        }

        try {
            $branch = (int) config('zenhr.attendance_branch_id', 0);
            if ($branch <= 0) {
                throw new RuntimeException('Missing ZENHR_ATTENDANCE_BRANCH_ID.');
            }
            $local = DB::connection('sqlsrv')
                ->table('adms.zenhr_attendance_outbox')
                ->where('delivery_state', 'pending')
                ->orderBy('punched_at_local')
                ->get(['badge_number', 'punched_at_local']);
            if ($local->isEmpty()) {
                $this->info('No pending punches. No data posted.');
                return self::SUCCESS;
            }

            $from = CarbonImmutable::parse(substr((string) $local->min('punched_at_local'), 0, 10), 'Asia/Dubai')
                ->subDay()->toDateString();
            $to = CarbonImmutable::parse(substr((string) $local->max('punched_at_local'), 0, 10), 'Asia/Dubai')
                ->addDay()->toDateString();

            $token = $oauth->getUsableAccessToken();
            $prefix = rtrim((string) config('zenhr.api_base'), '/').'/branches/'.$branch;
            $employees = $this->readPages($prefix.'/employees', $token, []);
            $records = $this->readPages($prefix.'/attendance_records', $token, [
                'filter[attendance_date][from]' => $from,
                'filter[attendance_date][to]' => $to,
            ]);

            // Match aliases to sets of ZenHR employee IDs; never resolve an
            // ambiguous mapping or assume physical device means ZenHR branch.
            $aliases = [];
            foreach ($employees as $r) {
                if (! is_array($r)) continue;
                $id = filter_var($r['id'] ?? null, FILTER_VALIDATE_INT);
                $number = ZenHrRecordValues::scalar($r['employment_number'] ?? null);
                if ($id !== false && $id > 0 && $number !== null) {
                    $aliases[$number][$id]['employee_number'] = true;
                }
            }
            foreach ($records as $r) {
                if (! is_array($r)) continue;
                $employee = is_array($r['employee'] ?? null) ? $r['employee'] : [];
                $id = filter_var($employee['id'] ?? null, FILTER_VALIDATE_INT);
                $card = ZenHrRecordValues::scalar($r['card_id'] ?? null);
                if ($id !== false && $id > 0 && $card !== null) {
                    $aliases[$card][$id]['attendance_card'] = true;
                }
            }

            $unique = [];
            $matched = $unmatched = $ambiguous = $byNumber = $byCard = 0;
            foreach ($local as $r) {
                $badge = trim((string) $r->badge_number);
                $unique[$badge] = true;
                $hits = $aliases[$badge] ?? [];
                if (count($hits) === 0) {
                    $unmatched++;
                } elseif (count($hits) > 1) {
                    $ambiguous++;
                } else {
                    $matched++;
                    $sources = reset($hits);
                    if (isset($sources['employee_number'])) $byNumber++;
                    if (isset($sources['attendance_card'])) $byCard++;
                }
            }

            $this->table(['ZenHR employee mapping · GET only', 'Result'], [
                ['ZenHR company branch', $branch],
                ['Remote employees loaded', count($employees)],
                ['Remote daily attendance loaded', count($records)],
                ['Remote attendance dates', $from.' to '.$to],
                ['Local pending punches', $local->count()],
                ['Distinct employee badge numbers', count($unique)],
                ['Punches matching unique ZenHR employee ID', $matched],
                ['Of matches: employment_number', $byNumber],
                ['Of matches: attendance card_id', $byCard],
                ['Unmatched punches (BLOCKED)', $unmatched],
                ['Ambiguous punches (BLOCKED)', $ambiguous],
                ['ZenHR attendance POST', 'DISABLED · no records sent'],
            ]);
            $this->warn('Mapping does NOT resolve mandatory request_type (0=in, 1=out), or rule out already-imported punches.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            // Deliberately avoid emitting HTTP bodies, tokens or employee data.
            $this->error('ZenHR mapping audit stopped: '.$e->getMessage());
            return self::FAILURE;
        }
    }

    private function readPages(string $url, string $token, array $filters): array
    {
        $all = [];
        $expected = null;
        $pages = null;
        for ($page = 1; $page <= 100; $page++) {
            if ($page > 1) usleep(350000);
            $query = array_merge(['page' => $page, 'limit' => 100], $filters);
            $r = Http::withToken($token)->acceptJson()->timeout(30)->get($url, $query);
            if (! $r->successful()) {
                throw new RuntimeException('Read-only ZenHR GET HTTP '.$r->status().' (page '.$page.').');
            }
            $json = $r->json();
            if (! is_array($json) || ! is_array($json['data'] ?? null)
                || ! is_numeric($json['pagination']['total_entries'] ?? null)) {
                throw new RuntimeException('Unexpected ZenHR response/pagination format.');
            }
            $count = (int) $json['pagination']['total_entries'];
            if ($expected !== null && $expected !== $count) {
                throw new RuntimeException('ZenHR records changed mid-scan; rerun.');
            }
            $expected = $count;
            $pages ??= max(1, (int) ($json['pagination']['total_pages'] ?? ceil($count / 100)));
            if ($pages > 100 || ($page < $pages && $json['data'] === [])) {
                throw new RuntimeException('Incomplete ZenHR pagination.');
            }
            $all = array_merge($all, $json['data']);
            if ($page >= $pages) break;
        }
        if ($expected === null || count($all) !== $expected) {
            throw new RuntimeException('ZenHR pagination incomplete; no identity accepted.');
        }
        return $all;
    }
}
