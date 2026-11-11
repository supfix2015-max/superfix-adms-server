<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only preflight for writing NEW ADMS attendance directly to Att2000_V4.
 * Does not modify the original database and does not query biometric payloads.
 */
class InspectLegacyAttendanceWriteReadinessCommand extends Command
{
    protected $signature = 'adms:legacy-write-readiness
                            {--serial= : Optional physical device serial to check against historical records}';

    protected $description = 'Check Att2000_V4 Attendance INSERT requirements and existing values (read-only)';

    public function handle(): int
    {
        try {
            $db = DB::connection('legacy_sqlsrv');

            $columns = $db->select(
                "SELECT c.name AS ColumnName, ty.name AS SqlType,
                        c.is_nullable AS IsNullable, c.is_identity AS IsIdentity,
                        c.is_computed AS IsComputed, c.max_length AS MaxLength,
                        CASE WHEN c.default_object_id = 0 THEN 0 ELSE 1 END AS HasDefault
                 FROM sys.columns c
                 INNER JOIN sys.types ty ON ty.user_type_id = c.user_type_id
                 WHERE c.object_id = OBJECT_ID(N'dbo.Attendance', N'U')
                 ORDER BY c.column_id"
            );

            if ($columns === []) {
                $this->error('dbo.Attendance table or column metadata is not accessible.');
                return self::FAILURE;
            }

            $this->info('Original Att2000_V4 Attendance INSERT readiness (NO WRITES).');
            $this->table(['Column', 'Type', 'Null?', 'Identity?', 'Default?', 'Max bytes'], array_map(
                static fn (object $c): array => [
                    $c->ColumnName, $c->SqlType,
                    $c->IsNullable ? 'yes' : 'no',
                    $c->IsIdentity ? 'yes' : 'no',
                    $c->HasDefault ? 'yes' : 'no',
                    $c->MaxLength,
                ],
                $columns
            ));

            $provided = ['BadgeNumber','VerifyTime','VerifyType','Status','DeviceSerialNumber','InsertDate'];
            $required = array_values(array_filter($columns, static fn (object $c): bool =>
                ! $c->IsNullable && ! $c->IsIdentity && ! $c->IsComputed && ! $c->HasDefault
            ));
            $unmapped = array_values(array_filter($required, static fn (object $c): bool =>
                ! in_array(strtolower((string) $c->ColumnName), array_map('strtolower', $provided), true)
            ));
            $this->line('Required columns without NULL/default/identity: '.implode(', ', array_map(
                static fn (object $c): string => $c->ColumnName, $required
            )));
            if ($unmapped !== []) {
                $this->error('WRITE BLOCKER: required columns not yet mapped: '.implode(', ', array_map(
                    static fn (object $c): string => $c->ColumnName, $unmapped
                )));
            }

            $values = $db->select(
                "SELECT TOP (15) [Status], [VerifyType], COUNT_BIG(*) AS Total
                 FROM dbo.Attendance
                 GROUP BY [Status], [VerifyType]
                 ORDER BY Total DESC"
            );
            $this->table(
                ['Historical Status', 'Historical VerifyType', 'Rows'],
                array_map(static fn (object $x): array => [
                    $x->Status ?? 'NULL', $x->VerifyType ?? 'NULL', (int) $x->Total,
                ], $values)
            );

            $serial = trim((string) $this->option('serial'));
            if ($serial !== '') {
                if (! preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $serial)) {
                    $this->error('Invalid serial.');
                    return self::FAILURE;
                }
                $found = $db->table('dbo.Device')->where('DeviceSerialNumber', $serial)->count();
                $punches = $db->table('dbo.Attendance')->where('DeviceSerialNumber', $serial)->count();
                $this->table(['Serial check', 'Count'], [
                    ['Legacy registered device rows', $found],
                    ['Historical attendance rows', $punches],
                ]);
            }

            $this->warn('No records or schema changed; direct-write mode remains DISABLED by default.');
            return $unmapped !== [] ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            $this->error('Unable to inspect SQL Server attendance write readiness. Check read-only connection and permissions.');
            return self::FAILURE;
        }
    }
}
