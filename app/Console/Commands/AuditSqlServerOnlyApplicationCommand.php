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
            $operationalDriver = config('database.connections.sqlsrv.driver');
            $historicalDriver = config('database.connections.legacy_sqlsrv.driver');
            if ($default !== 'sqlsrv' || $operationalDriver !== 'sqlsrv' || $historicalDriver !== 'sqlsrv') {
                $this->error('Operational and historical database connections must both use SQL Server.');
                return self::FAILURE;
            }

            // Configurations registered by older images or packages do not
            // prove that Laravel is USING a MySQL database. Audit the actual
            // selected and connected drivers instead of failing on their names.
            $unusedDrivers = array_values(array_intersect(
                ['mysql', 'mariadb'], array_keys(config('database.connections', []))
            ));
            if ($unusedDrivers !== []) {
                $this->warn('Unused MySQL/MariaDB connection definitions remain in the runtime config: '
                    .implode(', ', $unusedDrivers).'. Verify the deployed config/database.php image version.');
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
                ['Default connection', $default],
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
