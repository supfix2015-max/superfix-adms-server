<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\LegacyAttendanceValueMapper;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * READ ONLY. Compares actual staged attendance with original dbo.Attendance
 * using the exact device serial, employee BadgeNumber, event timestamp and
 * direction, also reporting possible +/-4 hour clock-related matches.
 * Never adjusts time, acknowledges physical devices or writes attendance.
 */
class AuditHiliBackfillCommand extends Command
{
    protected $signature = 'adms:audit-hili-backfill
        {--serial=CEG9223661162 : Original physical device serial}
        {--from=2026-11-04 : First device-local UAE calendar day}
        {--to=2026-11-12 : Last device-local UAE calendar day}';

    protected $description = 'Read-only staged vs original attendance audit (exact, clock-offset, unmatched)';

    public function handle(): int
    {
        $serial = trim((string) $this->option('serial'));
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');

        if (! preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $serial)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)
            || $from > $to) {
            $this->error('Invalid serial, from or to. Use YYYY-MM-DD dates.');
            return self::FAILURE;
        }

        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, 'Asia/Dubai');
            $last = CarbonImmutable::createFromFormat('!Y-m-d', $to, 'Asia/Dubai');
            if (! $start || ! $last || $start->format('Y-m-d') !== $from
                || $last->format('Y-m-d') !== $to || $start->diffInDays($last) > 60) {
                throw new \InvalidArgumentException('Invalid dates or range greater than 60 days.');
            }
            $until = $last->addDay();
            $device = Device::query()->where('serial_number', $serial)->firstOrFail();
            $original = DB::connection('legacy_sqlsrv');

            if (! $original->table('dbo.Device')->where('DeviceSerialNumber', $serial)->exists()) {
                $this->error('Serial not found in original dbo.Device; refusing comparison.');
                return self::FAILURE;
            }

            $query = $device->attendanceLogs()->orderBy('id');
            $stageCount = (clone $query)->count();
            if ($stageCount > 10000) {
                $this->error('More than 10,000 staged rows. Refusing unbounded in-memory audit.');
                return self::FAILURE;
            }
            $staged = $query->get(['id', 'employee_code', 'log_time', 'raw_payload', 'log_type']);

            // Pad the official query by a day at each edge so possible +/−4h
            // clock shifts around midnight can be recognized.
            $official = $original->table('dbo.Attendance')
                ->where('DeviceSerialNumber', $serial)
                ->where('VerifyTime', '>=', $start->subDay()->format('Y-m-d H:i:s'))
                ->where('VerifyTime', '<', $until->addDay()->format('Y-m-d H:i:s'))
                ->select(['Id', 'BadgeNumber', 'VerifyTime', 'Status'])
                ->get();

            $originalIndex = [];
            foreach ($official as $item) {
                if (! $item->VerifyTime) {
                    continue;
                }
                $time = CarbonImmutable::parse((string) $item->VerifyTime, 'Asia/Dubai')
                    ->format('Y-m-d H:i:s');
                $key = $this->key((string) $item->BadgeNumber, (string) $item->Status, $time);
                $originalIndex[$key] = ($originalIndex[$key] ?? 0) + 1;
            }

            $allCodes = $staged->pluck('employee_code')->filter()->unique()->values()->all();
            $knownCodes = [];
            foreach (array_chunk($allCodes, 500) as $chunk) {
                foreach ($original->table('dbo.Employee')
                    ->whereIn('BadgeNumber', $chunk)->pluck('BadgeNumber') as $code) {
                    $knownCodes[trim((string) $code)] = true;
                }
            }

            $categories = [
                'exact' => 0,
                'plus_4h' => 0,
                'minus_4h' => 0,
                'ambiguous_offset' => 0,
                'not_in_original' => 0,
                'unknown_badge' => 0,
                'invalid_status' => 0,
                'duplicate_staging' => 0,
            ];
            $perDay = [];
            $samples = [];
            $seen = [];
            $inRange = 0;

            foreach ($staged as $entry) {
                if (! $entry->log_time) {
                    continue;
                }
                // adms log timestamps are stored as UTC instants; compare
                // their original device-local (Asia/Dubai) wall-clock time
                // against SQL Server's timezone-less dbo.VerifyTime.
                $local = CarbonImmutable::instance($entry->log_time)->setTimezone('Asia/Dubai');
                if ($local->lt($start) || $local->gte($until)) {
                    continue;
                }

                $inRange++;
                $day = $local->format('Y-m-d');
                $badge = trim((string) $entry->employee_code);
                $status = null;
                try {
                    $status = LegacyAttendanceValueMapper::status([
                        'raw_payload' => $entry->raw_payload,
                        'log_type' => $entry->log_type,
                    ]);
                } catch (\Throwable) {
                    // Keep malformed/uncertain statuses out of official data.
                }

                $label = 'not_in_original';
                $time = $local->format('Y-m-d H:i:s');
                $key = $this->key($badge, (string) $status, $time);

                if ($status === null) {
                    $label = 'invalid_status';
                } elseif ($badge === '' || ! isset($knownCodes[$badge])) {
                    $label = 'unknown_badge';
                } elseif (isset($seen[$key])) {
                    $label = 'duplicate_staging';
                } else {
                    $seen[$key] = true;
                    if (isset($originalIndex[$key])) {
                        $label = 'exact';
                    } else {
                        $plus = isset($originalIndex[$this->key(
                            $badge, $status, $local->addHours(4)->format('Y-m-d H:i:s')
                        )]);
                        $minus = isset($originalIndex[$this->key(
                            $badge, $status, $local->subHours(4)->format('Y-m-d H:i:s')
                        )]);

                        if ($plus && $minus) {
                            $label = 'ambiguous_offset';
                        } elseif ($plus) {
                            $label = 'plus_4h';
                        } elseif ($minus) {
                            $label = 'minus_4h';
                        }
                    }
                }

                $categories[$label]++;
                $perDay[$day] ??= array_fill_keys(array_keys($categories), 0);
                $perDay[$day][$label]++;

                if ($label !== 'exact' && count($samples) < 15) {
                    $samples[] = [
                        $entry->id, $badge, $time, $status ?: 'INVALID', $label,
                    ];
                }
            }

            $this->info("READ ONLY: {$serial}, {$from} to {$to} (Asia/Dubai)");
            $this->line('Staged total (all dates): '.$stageCount);
            $this->line('Staged within date range: '.$inRange);
            $this->line('Original SQL rows in padded query window: '.$official->count());

            $this->table(
                ['Day', 'Staged', 'Exact', '+4h match', '-4h match', 'No match', 'Other review'],
                collect($perDay)->sortKeys()->map(static fn ($c, $day) => [
                    $day,
                    array_sum($c),
                    $c['exact'],
                    $c['plus_4h'],
                    $c['minus_4h'],
                    $c['not_in_original'],
                    $c['ambiguous_offset'] + $c['unknown_badge']
                        + $c['invalid_status'] + $c['duplicate_staging'],
                ])->values()->all()
            );
            $this->table(
                ['Exact', '+4h', '-4h', 'Offset ambiguous', 'Unmatched', 'Unknown badge', 'Invalid status', 'Staged duplicate'],
                [[
                    $categories['exact'], $categories['plus_4h'], $categories['minus_4h'],
                    $categories['ambiguous_offset'], $categories['not_in_original'],
                    $categories['unknown_badge'], $categories['invalid_status'],
                    $categories['duplicate_staging'],
                ]]
            );

            if ($samples !== []) {
                $this->table(['Staging ID', 'Badge', 'Dubai local time', 'Direction', 'Category'], $samples);
            }
            $this->warn('No official or staged data was modified.');
            $this->warn('Only exact matches are confirmed duplicates. +4h/-4h indicate candidates, not permission to adjust timestamps.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            report($e);
            $this->error('Read-only comparison failed: '.$e->getMessage());
            return self::FAILURE;
        }
    }

    private function key(string $badge, string $status, string $time): string
    {
        return implode('|', [trim($badge), strtolower(trim($status)), $time]);
    }
}
