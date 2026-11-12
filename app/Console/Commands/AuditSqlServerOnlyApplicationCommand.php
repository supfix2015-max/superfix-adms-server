<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AuditSqlServerOnlyApplicationCommand extends Command
{
    protected $signature = 'adms:sqlserver-only-audit';

    protected $description = 'Read-only readiness audit for the SQL Server-only operational Laravel database';

    public function handle(): int
    {
        try {
            $default = config('database.default');
            if ($default !== 'sqlsrv' || isset(config('database.connections')['mysql'])) {
                $this->error('Laravel database still references a non-SQL Server operational connection.');
                return self::FAILURE;
            }

            $operational = DB::connection('sqlsrv');
            $legacy = DB::connection('legacy_sqlsrv');

            $operationalName = (string) ($operational->selectOne('SELECT DB_NAME() AS name')->name ?? '');
            $historicalName = (string) ($legacy->selectOne('SELECT DB_NAME() AS name')->name ?? '');

            if ($operationalName === '' || strcasecmp($operationalName, $historicalName) === 0) {
                $this->error('Unsafe configuration: original database and Laravel operational database must be distinct.');
                return self::FAILURE;
            }

            $this->table(['Source', 'Database'], [
                ['Laravel operations (SQL Server)', $operationalName],
                ['Original historical source (SQL Server)', $historicalName],
            ]);

            $tables = [
                'users', 'devices', 'employees', 'attendance_logs',
                'device_commands', 'raw_logs', 'employee_biometrics',
                'biometric_templates', 'jobs', 'failed_jobs', 'migrations',
            ];

            $this->table(['Operational table', 'Present'], array_map(
                static fn (string $table): array => [
                    $table,
                    Schema::connection('sqlsrv')->hasTable($table) ? 'YES' : 'NO',
                ],
                $tables
            ));

            $this->info('Read-only audit complete: no data modified, no MySQL connection queried.');
            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            $this->error('SQL Server operational database is not ready or unreachable. No data modified.');
            return self::FAILURE;
        }
    }
}
