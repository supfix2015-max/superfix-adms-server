<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scope the Edit License metadata flag to the operational adms schema.
 * Never ALTER the historical dbo.Device table.
 *
 * Production SQL login may intentionally have DML, not DDL privileges.
 * In that case a DBA can add the column via the narrow, idempotent
 * docs/sql/adms-devices-requires-license.sql script. This migration will
 * then recognize that column and mark itself as applied without ALTER.
 */
return new class extends Migration
{
    private const TABLE = 'adms.devices';

    private const COLUMN = 'requires_license';

    private function verifyConnection(): \Illuminate\Database\Connection
    {
        $connection = DB::connection('sqlsrv');
        $identity = $connection->selectOne(
            'SELECT DB_NAME() AS database_name, SCHEMA_NAME() AS default_schema'
        );

        if (strcasecmp((string) ($identity->database_name ?? ''), 'Att2000_V4') !== 0
            || strcasecmp((string) ($identity->default_schema ?? ''), 'adms') !== 0) {
            throw new \RuntimeException(
                'Refusing migration outside Att2000_V4 using the adms application login.'
            );
        }

        $object = $connection->selectOne(
            "SELECT OBJECT_ID(N'adms.devices', N'U') AS object_id"
        );
        if ($object === null || $object->object_id === null) {
            throw new \RuntimeException(
                'Required operational table Att2000_V4.adms.devices was not found or is not visible. '
                .'No dbo table will be modified.'
            );
        }

        return $connection;
    }

    private function columnExists(\Illuminate\Database\Connection $connection): bool
    {
        $column = $connection->selectOne(
            "SELECT COL_LENGTH(N'adms.devices', N'requires_license') AS column_length"
        );

        return $column !== null && $column->column_length !== null;
    }

    public function up(): void
    {
        $connection = $this->verifyConnection();

        // Pre-provisioning by a restricted, dedicated DBA works and avoids
        // granting broad DDL to the long-running ADMS application user.
        if ($this->columnExists($connection)) {
            return;
        }

        $permission = $connection->selectOne(
            "SELECT HAS_PERMS_BY_NAME(N'adms.devices', N'OBJECT', N'ALTER') AS can_alter"
        );

        if ((int) ($permission->can_alter ?? 0) !== 1) {
            throw new \RuntimeException(
                'The ADMS SQL login has no ALTER permission on adms.devices. '
                .'Have a SQL Server administrator apply the single-table, '
                .'idempotent docs/sql/adms-devices-requires-license.sql script, '
                .'then rerun this targeted migration. Do NOT grant dbo or database-wide ALTER.'
            );
        }

        // Never an unqualified "devices": explicitly target adms.devices.
        Schema::connection('sqlsrv')->table(self::TABLE, function (Blueprint $table): void {
            $table->boolean(self::COLUMN)->default(false);
        });
    }

    public function down(): void
    {
        $connection = $this->verifyConnection();

        if (! $this->columnExists($connection)) {
            return;
        }

        $permission = $connection->selectOne(
            "SELECT HAS_PERMS_BY_NAME(N'adms.devices', N'OBJECT', N'ALTER') AS can_alter"
        );
        if ((int) ($permission->can_alter ?? 0) !== 1) {
            throw new \RuntimeException('Rollback requires ALTER on adms.devices.');
        }

        Schema::connection('sqlsrv')->table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }
};
