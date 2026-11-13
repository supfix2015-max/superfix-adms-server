<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\LegacyAttendanceValueMapper;
use App\Services\LegacyAttendanceWriter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Manually authorized, idempotent ORIGINAL attendance repair.
 *
 * Dry run by default. Never alters historical rows, template data, employee
 * data or the staged records. The only write path is the existing restricted
 * LegacyAttendanceWriter, which also holds SQL sp_getapplock and checks exact
 * original duplicates before each insert.
 */
class ImportMissingHiliAttendanceCommand extends Command
{
    protected $signature = 'adms:import-missing-hili-attendance
        {--serial=CEG9223661162 : Exact original device serial}
        {--from=2026-11-04 : UAE first calendar day}
        {--to=2026-11-12 : UAE last calendar day}
        {--apply : Explicitly insert unmatched original records}
        {--expect= : Required exact unmatched count before any INSERT}
        {--backup-confirmed : Operator confirms a fresh Att2000_V4 backup exists}
        {--confirm= : For Hili requires literal IMPORT-HILI-20261104-20261112}';

    protected $description = 'Preview or carefully insert Hili staged historical punches missing in dbo.Attendance';

    public function handle(): int
    {
        $serial = trim((string) $this->option('serial'));
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        $apply = (bool) $this->option('apply');

        // Limit the executable repair to the explicitly audited Hili window.
        // Other original terminals need individual previews and approval.
        if ($serial !== 'CEG9223661162' || $from !== '2026-11-04' || $to !== '2026-11-12') {
            $this->error('This controlled import is approved for Hili 2026-11-04..2026-11-12 only.');
            return self::FAILURE;
        }

        if ($apply && (
            ! $this->option('backup-confirmed')
            || (string) $this->option('confirm') !== 'IMPORT-HILI-20261104-20261112'
            || ! ctype_digit((string) $this->option('expect'))
        )) {
            $this->error('Apply requires --backup-confirmed --expect=<dry-run missing> --confirm=IMPORT-HILI-20261104-20261112.');
            return self::FAILURE;
        }

        try {
            $original = DB::connection('legacy_sqlsrv');
            if (! $original->table('dbo.Device')
                ->where('DeviceSerialNumber', $serial)->exists()) {
                throw new \RuntimeException('Original dbo.Device does not contain this serial.');
            }

            if ((string) config('legacy_attendance.attendance_wallclock_timezone') !== 'Asia/Dubai') {
                throw new \RuntimeException('Require LEGACY_SQLSRV_ATTENDANCE_TIMEZONE=Asia/Dubai.');
            }

            if ($apply && (
                ! config('legacy_attendance.attendance_write_enabled', false)
                || ! in_array($serial, config('legacy_attendance.attendance_write_serials', []), true)
                || config('legacy_attendance.attendance_write_all_registered', false)
                || (string) config('database.connections.legacy_sqlsrv_writer.database') !== 'Att2000_V4'
            )) {
                throw new \RuntimeException('Direct writer is not safely enabled for Hili only.');
            }

            $device = Device::query()->where('serial_number', $serial)->firstOrFail();
            $start = CarbonImmutable::parse($from.' 00:00:00', 'Asia/Dubai');
            $until = CarbonImmutable::parse($to.' 00:00:00', 'Asia/Dubai')->addDay();
            $query = $device->attendanceLogs()->orderBy('id');
            $allCount = (clone $query)->count();
            if ($allCount > 10000) {
                throw new \RuntimeException('Too many staged events for a controlled in-memory import.');
            }

            $staged = $query->get([
                'id', 'employee_code', 'log_time', 'raw_payload',
                'log_type', 'verify_type',
            ])->filter(static function ($e) use ($start, $until): bool {
                if (! $e->log_time) {
                    return false;
                }
                $local = CarbonImmutable::instance($e->log_time)->setTimezone('Asia/Dubai');
                return $local->gte($start) && $local->lt($until);
            });

            $historical = $original->table('dbo.Attendance')
                ->where('DeviceSerialNumber', $serial)
                ->where('VerifyTime', '>=', $start->subDay()->format('Y-m-d H:i:s'))
                ->where('VerifyTime', '<', $until->addDay()->format('Y-m-d H:i:s'))
                ->get(['BadgeNumber', 'VerifyTime', 'Status']);

            $existing = [];
            foreach ($historical as $row) {
                if (! $row->VerifyTime) {
                    continue;
                }
                $wallClock = CarbonImmutable::parse((string) $row->VerifyTime, 'Asia/Dubai')
                    ->format('Y-m-d H:i:s');
                $existing[$this->key((string) $row->BadgeNumber, (string) $row->Status, $wallClock)] = true;
            }

            $badges = $staged->pluck('employee_code')->filter()->unique()->values();
            $known = [];
            foreach ($badges->chunk(500) as $chunk) {
                foreach ($original->table('dbo.Employee')
                    ->whereIn('BadgeNumber', $chunk->all())->pluck('BadgeNumber') as $code) {
                    $known[trim((string) $code)] = true;
                }
            }

            $counts = [
                'exact' => 0,
                'missing' => 0,
                'offset_match' => 0,
                'unknown_badge' => 0,
                'invalid_status' => 0,
                'staged_duplicate' => 0,
            ];
            $seen = [];
            $pending = collect();
            $byDay = [];

            foreach ($staged as $row) {
                $badge = trim((string) $row->employee_code);
                $date = CarbonImmutable::instance($row->log_time)->setTimezone('Asia/Dubai');
                $time = $date->format('Y-m-d H:i:s');
                $day = $date->format('Y-m-d');

                $raw = $row->raw_payload;
                try {
                    $status = LegacyAttendanceValueMapper::status([
                        'raw_payload' => $raw,
                        'log_type' => $row->log_type,
                    ]);
                    $verify = LegacyAttendanceValueMapper::verify([
                        'raw_payload' => $raw,
                        'verify_type' => $row->verify_type,
                    ]);
                } catch (\Throwable) {
                    $counts['invalid_status']++;
                    continue;
                }

                $key = $this->key($badge, $status, $time);
                if ($badge === '' || ! isset($known[$badge])) {
                    $counts['unknown_badge']++;
                } elseif (isset($seen[$key])) {
                    $counts['staged_duplicate']++;
                } elseif (isset($existing[$key])) {
                    $seen[$key] = true;
                    $counts['exact']++;
                } elseif (
                    isset($existing[$this->key($badge, $status, $date->addHours(4)->format('Y-m-d H:i:s'))])
                    || isset($existing[$this->key($badge, $status, $date->subHours(4)->format('Y-m-d H:i:s'))])
                ) {
                    $seen[$key] = true;
                    $counts['offset_match']++;
                } else {
                    $seen[$key] = true;
                    $counts['missing']++;
                    $byDay[$day] = ($byDay[$day] ?? 0) + 1;
                    $pending->push([
                        'employee_code' => $badge,
                        'log_time' => $row->log_time,
                        'raw_payload' => $raw,
                        'log_type' => $row->log_type,
                        'verify_type' => $row->verify_type,
                        'staging_id' => $row->id,
                    ]);
                }
            }

            ksort($byDay);
            $this->info("Hili historical repair candidate inspection: {$from} to {$to}");
            $this->table(
                ['Staged in window', 'Already in original', 'Missing', 'Possible 4-hour match',
                    'Unknown employee', 'Invalid status', 'Duplicate staging'],
                [[
                    $staged->count(), $counts['exact'], $counts['missing'],
                    $counts['offset_match'], $counts['unknown_badge'],
                    $counts['invalid_status'], $counts['staged_duplicate'],
                ]]
            );
            $this->table(['Date (Dubai)', 'Missing original punches'], array_map(
                static fn ($day, $count) => [$day, $count],
                array_keys($byDay), array_values($byDay)
            ));

            if (! $apply) {
                $this->warn('PREVIEW ONLY. No records modified. Validate old device times and original SQL backup before applying.');
                return self::SUCCESS;
            }

            if ($counts['offset_match'] !== 0 || $counts['unknown_badge'] !== 0
                || $counts['invalid_status'] !== 0 || $counts['staged_duplicate'] !== 0) {
                throw new \RuntimeException('Unsafe candidate categories appeared; nothing was imported.');
            }

            if ($counts['missing'] !== (int) $this->option('expect')) {
                throw new \RuntimeException('Expected missing count changed; nothing was imported. Re-run preview.');
            }

            $writer = app(LegacyAttendanceWriter::class);
            $inserted = 0;
            $alreadyPresentOnRecheck = 0;

            foreach ($pending as $candidate) {
                unset($candidate['staging_id']);
                // The writer repeats original-badge checks and transactionally
                // deduplicates every event, even if another uploader races us.
                $result = $writer->persist($device, new Collection([$candidate]));
                $inserted += $result;
                $alreadyPresentOnRecheck += $result === 0 ? 1 : 0;
            }

            $this->info("COMPLETED: {$inserted} new records INSERTed into original dbo.Attendance.");
            $this->info("Skipped at final race-safe writer check: {$alreadyPresentOnRecheck}.");
            $this->warn('Existing original records were NOT updated/deleted; timestamps were NOT shifted. Staging remains intact.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            report($e);
            $this->error('Import stopped: '.$e->getMessage());
            $this->warn('If some rows were inserted before failure, re-running is idempotent; do a fresh preview first.');
            return self::FAILURE;
        }
    }

    private function key(string $badge, string $status, string $time): string
    {
        return implode('|', [trim($badge), strtolower(trim($status)), $time]);
    }
}
