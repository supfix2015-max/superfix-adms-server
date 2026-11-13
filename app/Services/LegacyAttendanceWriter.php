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

        if (! $this->canWriteFor($device)) {
            // This method is reserved for approved original SQL writers.
            // The receiver checks canWriteFor() first and stages unapproved
            // terminals in adms instead of returning retry-inducing HTTP 500.
            throw new RuntimeException('Device is not allowed for original SQL Server attendance writes.');
        }

        if (trim((string) config('database.connections.legacy_sqlsrv_writer.username')) === '') {
            throw new RuntimeException('Dedicated legacy SQL Server write credentials are missing.');
        }

        // A new punch must belong to an existing ORIGINAL SQL Server employee.
        // Do not create orphan employee codes from UA300's staging-only users.
        $badges = $records->pluck('employee_code')->map(
            static fn ($code): string => trim((string) $code)
        )->filter()->unique()->values();
        if ($badges->isEmpty() || $records->contains(
            static fn ($record): bool => ! is_array($record) || trim((string) ($record['employee_code'] ?? '')) === ''
        )) {
            throw new RuntimeException('No valid attendance badge identifiers.');
        }

        $legacyReader = DB::connection('legacy_sqlsrv');
        foreach ($badges->chunk(500) as $chunk) {
            $known = $legacyReader->table('dbo.Employee')
                ->whereIn('BadgeNumber', $chunk->all())
                ->pluck('BadgeNumber')
                ->map(static fn ($value): string => trim((string) $value))
                ->unique();

            if ($known->count() !== $chunk->count()) {
                throw new RuntimeException(
                    'One or more device badge numbers are absent from Att2000_V4.Employee. No SQL Server punch was written.'
                );
            }
        }

        // Use the verified DEVICE serial consistently for deduplication and
        // the original dbo.Attendance device identity. Previously $serial was
        // never initialized in persist(), causing live Hili writes to fail.
        $serial = trim((string) $device->serial_number);
        if ($serial === '') {
            throw new RuntimeException('Approved attendance writer has no device serial.');
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

            $state = LegacyAttendanceValueMapper::status($record);
            $verify = LegacyAttendanceValueMapper::verify($record);
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


    /**
     * Read-only device gate for the ingestion path.
     * Original SQL writes are limited to explicitly approved serial numbers
     * or (only with a separate opt-in) exact legacy-registered serials.
     *
     * Non-approved terminals are still permitted to stage their accepted
     * ATTLOG records in adms; they must not be sent to dbo.Attendance.
     */
    public function canWriteFor(Device $device): bool
    {
        if (! config('legacy_attendance.attendance_write_enabled', false)) {
            return false;
        }

        $serial = trim((string) $device->serial_number);
        $allowedSerials = config('legacy_attendance.attendance_write_serials', []);
        if ($serial !== '' && in_array($serial, $allowedSerials, true)) {
            return true;
        }

        if ($serial === ''
            || ! config('legacy_attendance.attendance_write_all_registered', false)
            || ! preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $serial)) {
            return false;
        }

        return DB::connection('legacy_sqlsrv')
            ->table('dbo.Device')
            ->where('DeviceSerialNumber', $serial)
            ->exists();
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
