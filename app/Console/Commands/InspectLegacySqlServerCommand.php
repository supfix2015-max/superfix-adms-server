<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only metadata discovery for the old SQL Server database.
 * Does not query employee records or biometric template payloads.
 */
class InspectLegacySqlServerCommand extends Command
{
    protected $signature = 'adms:inspect-legacy-sqlserver
                            {--table= : Optional table name such as dbo.USERINFO to inspect columns}';

    protected $description = 'List tables and optional columns in the legacy SQL Server database (read-only)';

    public function handle(): int
    {
        if (! extension_loaded('pdo_sqlsrv')) {
            $this->error('The pdo_sqlsrv PHP extension is not installed in this runtime.');

            return self::FAILURE;
        }

        if (trim((string) config('database.connections.legacy_sqlsrv.host')) === ''
            || trim((string) config('database.connections.legacy_sqlsrv.database')) === '') {
            $this->error('Set LEGACY_SQLSRV_HOST and LEGACY_SQLSRV_DATABASE before running this command.');

            return self::FAILURE;
        }

        try {
            $connection = DB::connection('legacy_sqlsrv');
            $connection->getPdo();

            $tables = $connection->select(
                "SELECT TABLE_SCHEMA, TABLE_NAME
                 FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_TYPE = 'BASE TABLE'
                 ORDER BY TABLE_SCHEMA, TABLE_NAME"
            );

            $this->info('Connected to legacy SQL Server; table metadata only (no employee data read).');
            $this->table(
                ['Schema', 'Table'],
                array_map(static fn (object $row): array => [$row->TABLE_SCHEMA, $row->TABLE_NAME], $tables)
            );

            $selectedTable = trim((string) $this->option('table'));

            if ($selectedTable !== '') {
                $nameParts = explode('.', $selectedTable, 2);
                $schema = count($nameParts) === 2 ? $nameParts[0] : 'dbo';
                $name = count($nameParts) === 2 ? $nameParts[1] : $nameParts[0];

                $columns = $connection->select(
                    "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE
                     FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                     ORDER BY ORDINAL_POSITION",
                    [$schema, $name]
                );

                if ($columns === []) {
                    $this->warn('No visible columns found for the requested table (check spelling or permissions).');

                    return self::FAILURE;
                }

                $this->table(
                    ['Column', 'Type', 'Nullable'],
                    array_map(
                        static fn (object $row): array => [$row->COLUMN_NAME, $row->DATA_TYPE, $row->IS_NULLABLE],
                        $columns
                    )
                );
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            // Deliberately do not echo the raw database exception (it can contain connection details).
            report($exception);
            $this->error('Unable to inspect legacy SQL Server. Check network, credentials, TLS and driver setup.');

            return self::FAILURE;
        }
    }
}
