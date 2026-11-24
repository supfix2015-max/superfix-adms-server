<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Services\LegacyAttendanceValueMapper;
use App\Services\LegacyAttendanceWriter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Idempotently promotes accepted staged punches into the ORIGINAL attendance
 * ledger, without updating or deleting existing original rows.
 *
 * Operational safety:
 * - read-only by default; --apply AND explicit environment opt-in required.
 * - exactly the 18 branch terminals (excludes old/office records).
 * - unknown badges, ambiguous statuses, future dates, old Hili clocks are
 *   skipped for manual reconciliation.
 * - persistent-ish file cursor; restart/redeploy rescans safely and idempotently.
 * - INSERT is serialized/deduplicated by LegacyAttendanceWriter.
 */
final class PromoteStagedAttendanceCommand extends Command
{
    private const CACHE_KEY = 'adms:attendance-promoter:last-scanned-id';

    private const EXCLUDED = [
        'A555180560731', // Old Samha 2
        'A555174860487', // Old Khaleej
        'CEG9201260631', // Old North Yas
        'BZD5251200272', // Original replaced terminal
        'BZD5261200843', // Office test UA300
    ];

    protected $signature = 'adms:attendance-promote
        {--limit=250 : Number of staged rows to examine per invocation, max 1000}
        {--from-id= : Override saved cursor (for operator dry runs / reconciliations)}
        {--apply : Insert verified missing rows into original dbo.Attendance}';

    protected $description = 'Reconcile ADMS staged punches into original dbo.Attendance (dry-run by default)';

    public function handle(LegacyAttendanceWriter $writer): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) {
            $this->error('Limit must be between 1 and 1000.');
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $override = $this->option('from-id');
        $manual = $override !== null && $override !== '';
        $fromId = $manual ? filter_var($override, FILTER_VALIDATE_INT)
            : (int) Cache::store('file')->get(self::CACHE_KEY, 0);
        if ($fromId === false || $fromId < 0) {
            $this->error('Invalid --from-id.');
            return self::FAILURE;
        }

        if ($apply && ! config('legacy_attendance.attendance_promotion_enabled', false)) {
            $this->error('LEGACY_SQLSRV_ATTENDANCE_PROMOTER_ENABLED is false; no original writes.');
            return self::FAILURE;
        }
        if ($apply && (! config('legacy_attendance.attendance_write_enabled', false)
            || config('legacy_attendance.attendance_wallclock_timezone') !== 'Asia/Dubai'
            || trim((string) config('database.connections.legacy_sqlsrv_writer.username')) === '')) {
            $this->error('Original SQL writer is not safely configured; zero original writes.');
            return self::FAILURE;
        }

        $lastScanned = (int) $fromId;
        $stats = [
            'read' => 0, 'inserted' => 0, 'already_present' => 0,
            'candidate' => 0, 'review' => 0, 'excluded' => 0,
        ];
        try {
            if (! in_array(config('adms_instance.role'), ['console', 'dual'], true)) {
                throw new RuntimeException('Promoter must run only on the AT2 console or dual instance.');
            }

            $reader = DB::connection('legacy_sqlsrv');
            $original = $reader->selectOne("SELECT CAST(SERVERPROPERTY('ServerName') AS nvarchar(256)) AS server_name, DB_NAME() AS database_name");
            if (($original->database_name ?? '') !== 'Att2000_V4') {
                throw new RuntimeException('Refusing write to unexpected original database.');
            }
            if ($apply) {
                $target = DB::connection('legacy_sqlsrv_writer')->selectOne(
                    "SELECT CAST(SERVERPROPERTY('ServerName') AS nvarchar(256)) AS server_name, DB_NAME() AS database_name"
                );
                if (! $target || $target->server_name !== $original->server_name
                    || $target->database_name !== $original->database_name) {
                    throw new RuntimeException('Original writer and reader do not point to the same SQL Server/database.');
                }
            }

            $registered = $reader->table('dbo.Device')
                ->whereNotNull('DeviceSerialNumber')
                ->pluck('DeviceSerialNumber')
                ->map(fn ($v) => trim((string) $v))->filter()->unique()->flip();

            $branches = Device::query()->get(['id', 'serial_number', 'name'])
                ->filter(fn (Device $d) => ! in_array(trim((string) $d->serial_number), self::EXCLUDED, true))
                ->values();

            if ($branches->count() !== 18) {
                throw new RuntimeException('Branch roster must contain exactly 18 devices; found '.$branches->count());
            }
            $devices = $branches->keyBy('id');
            $missingDevices = $branches->filter(fn (Device $d) => ! $registered->has($d->serial_number));
            if ($missingDevices->isNotEmpty()) {
                throw new RuntimeException('Some branch serials are absent from original dbo.Device; refusing cutover.');
            }

            $rows = AttendanceLog::query()->where('id', '>', (int) $fromId)
                ->orderBy('id')->limit((int) $limit)
                ->get(['id', 'device_id', 'employee_code', 'log_time', 'raw_payload', 'log_type', 'verify_type']);
            $nowLocal = now('Asia/Dubai')->addMinutes(5)->format('Y-m-d H:i:s');

            // This is a process-local authorization only. The original writer
            // still verifies every serial against original dbo.Device.
            if ($apply) {
                config(['legacy_attendance.attendance_write_all_registered' => true]);
            }

            foreach ($rows as $row) {
                ++$stats['read'];
                $device = $devices->get($row->device_id);
                if ($device === null) {
                    ++$stats['excluded'];
                } else {
                    $serial = trim((string) $device->serial_number);
                    $local = CarbonImmutable::instance($row->log_time)
                        ->setTimezone('Asia/Dubai')->format('Y-m-d H:i:s');
                    $badge = trim((string) $row->employee_code);

                    if ($local < '2026-11-03 00:00:00' || $local > $nowLocal
                        || ($serial === 'CEG9223661162' && $local < '2026-11-12 17:10:00')
                        || $badge === '') {
                        ++$stats['review'];
                    } elseif (! $reader->table('dbo.Employee')->where('BadgeNumber', $badge)->exists()) {
                        ++$stats['review'];
                    } else {
                        $event = [
                            'employee_code' => $badge,
                            'log_time' => $row->log_time,
                            'raw_payload' => $row->raw_payload,
                            'log_type' => $row->log_type,
                            'verify_type' => $row->verify_type,
                        ];
                        try {
                            $status = LegacyAttendanceValueMapper::status($event);
                            if (LegacyAttendanceValueMapper::verify($event) === 'Unknown') {
                                ++$stats['review'];
                            } else {
                                // Any same badge+device+time but DIFFERENT status
                                // is a conflict, not an insertion to auto-approve.
                                $existing = $reader->table('dbo.Attendance')
                                    ->where('BadgeNumber', $badge)
                                    ->where('DeviceSerialNumber', $serial)
                                    ->where('VerifyTime', $local)->pluck('Status');
                                if ($existing->isNotEmpty()) {
                                    if ($existing->contains(fn ($v) => trim((string) $v) === $status)) {
                                        ++$stats['already_present'];
                                    } else {
                                        ++$stats['review'];
                                    }
                                } elseif ($apply) {
                                    $stats['inserted'] += $writer->persist($device, collect([$event]));
                                } else {
                                    ++$stats['candidate'];
                                }
                            }
                        } catch (RuntimeException $e) {
                            // Ambiguous or unsupported punch status stays in
                            // ADMS review, rather than mutating original SQL.
                            if (str_contains($e->getMessage(), 'Unsupported or ambiguous ATTLOG')) {
                                ++$stats['review'];
                            } else {
                                throw $e;
                            }
                        }
                    }
                }

                $lastScanned = (int) $row->id;
                // Cache tracks scan progress, NOT an authoritative commit.
                // Losing the file cache is safe: the writer deduplicates.
                if ($apply && ! $manual) {
                    Cache::store('file')->put(self::CACHE_KEY, $lastScanned, now()->addYears(2));
                }
            }

            $this->line($apply ? 'APPLIED — original INSERTs only' : 'DRY RUN — NO SQL WRITES');
            foreach ($stats as $name => $value) {
                $this->line($name.': '.$value);
            }
            $this->line('cursor_after_id: '.$lastScanned);
            if ($manual) {
                $this->warn('Manual --from-id scan did not advance the automatic worker cursor.');
            }
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Promotion stopped safely at/after staged row '.$lastScanned.': '.$e->getMessage());
            foreach ($stats as $name => $value) {
                $this->line($name.': '.$value);
            }
            return self::FAILURE;
        }
    }
}
