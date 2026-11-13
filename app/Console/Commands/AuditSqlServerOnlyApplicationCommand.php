<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class AuditSqlServerOnlyApplicationCommand extends Command
{
    protected $signature = 'adms:sqlserver-only-audit';

    protected $description = 'Read-only check: SQL Server Att2000_V4 historical dbo and isolated adms schema';

    public function handle(): int
    {
        try {
            if (config('database.default') !== 'sqlsrv'
                || config('database.connections.sqlsrv.driver') !== 'sqlsrv'
                || config('database.connections.legacy_sqlsrv.driver') !== 'sqlsrv'
                || strcasecmp((string) config('database.connections.sqlsrv.schema'), 'adms') !== 0) {
                $this->error('Unsafe default connection or app schema. Expected SQL Server and adms.');
                return self::FAILURE;
            }

            $app = DB::connection('sqlsrv');
            $legacy = DB::connection('legacy_sqlsrv');
            $a = $app->selectOne('SELECT DB_NAME() AS db, SCHEMA_NAME() AS schema_name');
            $h = $legacy->selectOne('SELECT DB_NAME() AS db');

            if (strcasecmp((string) ($a->db ?? ''), 'Att2000_V4') !== 0
                || strcasecmp((string) ($a->db ?? ''), (string) ($h->db ?? '')) !== 0
                || strcasecmp((string) ($a->schema_name ?? ''), 'adms') !== 0) {
                $this->error('UNSAFE: app must use Att2000_V4 with login DEFAULT_SCHEMA=adms.');
                return self::FAILURE;
            }

            $expectedHistorical = ['Employee', 'Attendance', 'Device', 'EmployeeTemplate'];
            $existingHistorical = $legacy->select(
                "SELECT t.name FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id WHERE s.name=N'dbo' AND t.name IN (N'Employee',N'Attendance',N'Device',N'EmployeeTemplate')"
            );
            $names = array_map(static fn (object $row): string => strtolower((string) $row->name), $existingHistorical);
            foreach ($expectedHistorical as $table) {
                if (! in_array(strtolower($table), $names, true)) {
                    $this->error('Expected dbo historical source table missing: '.$table);
                    return self::FAILURE;
                }
            }

            $dboRead = $app->selectOne(
                "SELECT
                 HAS_PERMS_BY_NAME(N'dbo.Attendance', N'OBJECT', N'INSERT') AS can_insert,
                 HAS_PERMS_BY_NAME(N'dbo.Attendance', N'OBJECT', N'UPDATE') AS can_update,
                 HAS_PERMS_BY_NAME(N'dbo.Attendance', N'OBJECT', N'DELETE') AS can_delete"
            );
            if ((int) ($dboRead->can_insert ?? 0) === 1
                || (int) ($dboRead->can_update ?? 0) === 1
                || (int) ($dboRead->can_delete ?? 0) === 1) {
                $this->error('UNSAFE: app login has write rights on historical dbo.Attendance.');
                return self::FAILURE;
            }

            $appTables = $app->select(
                "SELECT t.name FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id WHERE s.name=N'adms' ORDER BY t.name"
            );
            $present = array_map(static fn (object $t): string => strtolower((string) $t->name), $appTables);
            $expected = ['migrations', 'users', 'devices', 'employees', 'attendance_logs',
                'device_commands', 'raw_logs', 'jobs', 'employee_biometrics'];
            $this->table(['Item', 'Value'], [
                ['SQL Server database', $a->db],
                ['App default schema', $a->schema_name],
                ['Historical source', 'dbo.* (read-only via legacy login)'],
                ['Original historical tables found', count($names)],
                ['App tables found', count($present)],
                ['App account may write historical attendance', 'NO'],
            ]);
            $this->table(['App schema table (adms.*)', 'Exists'], array_map(
                static fn (string $name): array => [$name, in_array($name, $present, true) ? 'YES' : 'NO'],
                $expected
            ));
            $this->info('Read-only audit finished. Database and source records unchanged.');
            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            $this->error('Could not audit SQL Server schema safely. No data modified.');
            return self::FAILURE;
        }
    }
}
