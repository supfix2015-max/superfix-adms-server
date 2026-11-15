<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use App\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only preflight for the approved two-database attendance cutover.
 * No writes, no device commands and no ZenHR requests.
 */
final class AttendanceSourceAuditCommand extends Command
{
    protected $signature = 'adms:attendance-source-audit
        {--serial=* : Repeat to inspect specific device serial numbers; default all legacy-registered devices}
        {--sample=50 : Maximum staged events sampled per device, 1 through 100}';

    protected $description = 'Compare legacy dbo attendance with ADMS staging before any cutover (READ ONLY)';

    public function handle(): int
    {
        $sample = filter_var($this->option('sample'), FILTER_VALIDATE_INT);
        if ($sample === false || $sample < 1 || $sample > 100) {
            $this->error('--sample must be between 1 and 100.');
            return self::FAILURE;
        }

        try {
            $legacy = DB::connection('legacy_sqlsrv');
            $startLocal = '2026-11-03 00:00:00';
            $sendLocal = '2026-11-15 00:00:00';
            $startUtc = CarbonImmutable::parse($startLocal, 'Asia/Dubai')->utc()->format('Y-m-d H:i:s');
            $sendUtc = CarbonImmutable::parse($sendLocal, 'Asia/Dubai')->utc()->format('Y-m-d H:i:s');

            $registered = $legacy->table('dbo.Device')
                ->whereNotNull('DeviceSerialNumber')
                ->pluck('DeviceSerialNumber')
                ->map(fn ($serial) => trim((string) $serial))
                ->filter()->unique()->flip();

            $requested = collect($this->option('serial'))
                ->map(fn ($serial) => trim((string) $serial))
                ->filter()->unique()->values();
            $devices = Device::query()->get(['id', 'serial_number', 'name'])
                ->filter(fn (Device $device) => $requested->isEmpty()
                    ? $registered->has(trim((string) $device->serial_number))
                    : $requested->contains(trim((string) $device->serial_number)))
                ->sortBy('serial_number')->values();

            if ($devices->isEmpty()) {
                $this->warn('No matching ADMS devices; no databases were changed.');
                return self::FAILURE;
            }

            $this->info('READ ONLY — no data written to either database or ZenHR.');
            $this->line('Operational SQL: '.config('database.connections.sqlsrv.database'));
            $this->line('Original SQL: '.config('database.connections.legacy_sqlsrv.database'));
            $this->line('Review window: '.$startLocal.' onward; outbound cutoff: '.$sendLocal.' (Asia/Dubai).');
            $this->line('NOTICE: Counts alone do not prove that punches match; sample matching ignores punch status.');
            $rows = [];

            foreach ($devices as $device) {
                $serial = trim((string) $device->serial_number);
                $legacyRegistered = $registered->has($serial);
                $staged = AttendanceLog::query()->where('device_id', $device->id)
                    ->where('log_time', '>=', $startUtc);
                $stagedSince = (clone $staged)->count();
                $stagedFrom15 = (clone $staged)->where('log_time', '>=', $sendUtc)->count();

                $dbo = $legacy->table('dbo.Attendance')
                    ->where('DeviceSerialNumber', $serial)
                    ->where('VerifyTime', '>=', $startLocal);
                $dboSince = (clone $dbo)->count();
                $dboFrom15 = (clone $dbo)->where('VerifyTime', '>=', $sendLocal)->count();

                $sampled = (clone $staged)->orderByDesc('id')->limit((int) $sample)
                    ->get(['employee_code', 'log_time']);
                $badges = $sampled->pluck('employee_code')
                    ->map(fn ($badge) => trim((string) $badge))->filter()->unique()->values();
                $knownBadges = $badges->isEmpty() ? collect()
                    : $legacy->table('dbo.Employee')->whereIn('BadgeNumber', $badges->all())
                        ->pluck('BadgeNumber')->map(fn ($badge) => trim((string) $badge))->flip();

                $local = $sampled->map(fn (AttendanceLog $log) => [
                    'badge' => trim((string) $log->employee_code),
                    'time' => CarbonImmutable::instance($log->log_time)
                        ->setTimezone('Asia/Dubai')->format('Y-m-d H:i:s'),
                ]);
                $candidateMatches = collect();
                if ($local->isNotEmpty() && $badges->isNotEmpty()) {
                    $candidateMatches = $legacy->table('dbo.Attendance')
                        ->where('DeviceSerialNumber', $serial)
                        ->whereIn('BadgeNumber', $badges->all())
                        ->whereBetween('VerifyTime', [
                            $local->min('time'), $local->max('time'),
                        ])
                        ->get(['BadgeNumber', 'VerifyTime'])
                        ->mapWithKeys(fn ($r) => [
                            trim((string) $r->BadgeNumber).'|'.
                                ($r->VerifyTime instanceof \DateTimeInterface
                                    ? $r->VerifyTime->format('Y-m-d H:i:s')
                                    : substr((string) $r->VerifyTime, 0, 19)) => true,
                        ]);
                }

                $missingSample = $local->filter(fn (array $row) => ! $candidateMatches
                    ->has($row['badge'].'|'.$row['time']))->count();
                $unknownSample = $local->filter(fn (array $row) => ! $knownBadges
                    ->has($row['badge']))->count();

                $rows[] = [
                    $serial,
                    $legacyRegistered ? 'YES' : 'NO',
                    $stagedSince,
                    $dboSince,
                    $stagedFrom15,
                    $dboFrom15,
                    $missingSample.'/'.$local->count(),
                    $unknownSample,
                ];
            }

            $this->table([
                'Serial', 'In legacy', 'ADMS >=Nov3', 'dbo >=Nov3',
                'ADMS >=Nov15', 'dbo >=Nov15', 'Sample absent*', 'Unknown badges*',
            ], $rows);
            $this->warn('* Sample indicators are READ-ONLY clues, NOT an approved insert list.');
            $this->warn('Never promote records until timezone, employee badge, status and duplicates are reconciled.');
            $this->warn('No backups were taken or verified by this command.');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Read-only audit failed: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
