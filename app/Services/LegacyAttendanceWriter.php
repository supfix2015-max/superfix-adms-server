<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Optional first-device cutover for writing NEW attendance events into the
 * original Att2000_V4.dbo.Attendance table, without migrating/changing tables.
 *
 * Disabled by default. Must use a separate SQL login with SELECT + INSERT on
 * dbo.Attendance only. No permissions to change legacy employees or biometrics.
 */
class LegacyAttendanceWriter
{
    private bool $validatedSchema = false;

    /** @return int Number of new original SQL Server attendance rows written. */
    public function persist(Device $device, Collection $records): int
    {
        if (! config('legacy_attendance.attendance_write_enabled', false)) {
            return 0;
        }

        $serial = trim((string) $device->serial_number);
        $allowedSerials = config('legacy_attendance.attendance_write_serials', []);

        if ($serial === '' || $allowedSerials === [] || ! in_array($serial, $allowedSerials, true)) {
            // Never ACK a terminal that was mistakenly cut over without SQL write support.
            throw new RuntimeException('Device is not allowlisted for original SQL Server attendance writes.');
        }

        if (trim((string) config('database.connections.legacy_sqlsrv_writer.username')) === '') {
            throw new RuntimeException('Dedicated legacy SQL Server write credentials are missing.');
        }

        $db = DB::connection('legacy_sqlsrv_writer');
        $this->assertSchemaCompatible($db);

        $inserted = 0;
        foreach ($records as $record) {
            if (! is_array($record) || trim((string) ($record['employee_code'] ?? '')) === '') {
                throw new RuntimeException('Invalid attendance data for original SQL Server.');
            }

            // Device timestamps are local time without an offset. Persist in the
            // SAME device-local wall-clock representation as the original database.
            $date = $record['log_time'];
            if (! $date instanceof \DateTimeInterface) {
                throw new RuntimeException('Missing normalized attendance timestamp.');
            }
            $deviceTimezone = (string) config('legacy_attendance.attendance_wallclock_timezone', '');
            if ($deviceTimezone === '') {
                throw new RuntimeException('Configure LEGACY_SQLSRV_ATTENDANCE_TIMEZONE before direct SQL writes.');
            }
            $localTime = \Carbon\CarbonImmutable::instance($date)
                ->setTimezone($deviceTimezone)
                ->format('Y-m-d H:i:s');

            $state = match ((string) ($record['log_type'] ?? '')) {
                'checkin' => 'Check-In',
                'checkout' => 'Check-Out',
                'break' => 'Break',
                'overtime' => 'Overtime',
                default => 'Unknown',
            };
            $verify = match ((string) ($record['verify_type'] ?? '')) {
                'fingerprint' => 'FingerPrint',
                'face' => 'Face',
                'rfid' => 'Card',
                'password' => 'Password',
                'palm' => 'Palm',
                default => 'Unknown',
            };
            $badge = trim((string) $record['employee_code']);

            $inserted += $db->transaction(function () use ($db, $serial, $badge, $localTime, $state, $verify): int {
                // Serialize retries from concurrent polls without adding tables/indexes
                // to the ORIGINAL SQL Server database.
                $lockName = 'adms-attendance:'.hash('sha256', implode('|', [$serial, $badge, $localTime, $state]));
                $lock = $db->selectOne(
                    "SET NOCOUNT ON;
                     DECLARE @result int;
                     EXEC @result = sp_getapplock
                         @Resource = ?, @LockMode = 'Exclusive',
                         @LockOwner = 'Transaction', @LockTimeout = 10000;
                     SELECT @result AS lock_result;",
                    [$lockName]
                );

                if (! $lock || (int) $lock->lock_result < 0) {
                    throw new RuntimeException('Could not acquire SQL Server event deduplication lock.');
                }

                // Check the historical table first; device backfills should not
                // recreate punches already received by the old attendance server.
                $exists = $db->table('dbo.Attendance')
                    ->where('BadgeNumber', $badge)
                    ->where('DeviceSerialNumber', $serial)
                    ->where('VerifyTime', $localTime)
                    ->where('Status', $state)
                    ->exists();

                if ($exists) {
                    return 0;
                }

                $db->table('dbo.Attendance')->insert([
                    'BadgeNumber' => $badge,
                    'VerifyTime' => $localTime,
                    'DeviceSerialNumber' => $serial,
                    'VerifyType' => $verify,
                    'Status' => $state,
                    'InsertDate' => now(config('legacy_attendance.attendance_wallclock_timezone'))->format('Y-m-d H:i:s'),
                ]);

                return 1;
            }, 1);
        }

        return $inserted;
    }

    private function assertSchemaCompatible($db): void
    {
        if ($this->validatedSchema) {
            return;
        }

        $columns = $db->select(
            "SELECT c.name, c.is_nullable, c.is_identity, c.is_computed,
                    CASE WHEN c.default_object_id = 0 THEN 0 ELSE 1 END AS has_default
             FROM sys.columns c
             WHERE c.object_id = OBJECT_ID(N'dbo.Attendance', N'U')"
        );
        if ($columns === []) {
            throw new RuntimeException('Original SQL Server Attendance table schema unavailable.');
        }

        $provided = ['BadgeNumber','VerifyTime','DeviceSerialNumber','VerifyType','Status','InsertDate'];
        $found = array_map(static fn (object $c): string => strtolower((string) $c->name), $columns);
        foreach ($provided as $name) {
            if (! in_array(strtolower($name), $found, true)) {
                throw new RuntimeException('Legacy Attendance table missing required mapping column.');
            }
        }

        foreach ($columns as $column) {
            if (! $column->is_nullable && ! $column->is_identity && ! $column->is_computed && ! $column->has_default
                && ! in_array(strtolower((string) $column->name), array_map('strtolower', $provided), true)) {
                throw new RuntimeException('Legacy Attendance has unmapped mandatory fields. Run write readiness first.');
            }
        }

        $this->validatedSchema = true;
    }
}
