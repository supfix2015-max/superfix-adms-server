<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection('sqlsrv');
        $identity = $db->selectOne("SELECT DB_NAME() AS database_name, SCHEMA_NAME() AS default_schema");
        if (strcasecmp((string) $identity->database_name, 'Att2000_V4') !== 0
            || strcasecmp((string) $identity->default_schema, 'adms') !== 0) {
            throw new RuntimeException('Refusing fingerprint review setup outside Att2000_V4.adms.');
        }
        if ($db->selectOne("SELECT OBJECT_ID(N'adms.fingerprint_sync_reviews', N'U') AS object_id")?->object_id !== null) return;
        $permission = $db->selectOne("SELECT HAS_PERMS_BY_NAME(DB_NAME(),'DATABASE','CREATE TABLE') AS can_create, HAS_PERMS_BY_NAME(N'adms','SCHEMA','ALTER') AS can_alter");
        if ((int)$permission->can_create !== 1 || (int)$permission->can_alter !== 1) {
            throw new RuntimeException('Run docs/sql/adms-fingerprint-sync-reviews.sql as DBA, then rerun this targeted migration. Do not grant broad DDL.');
        }
        // Apply exactly the DDL section of the bundled idempotent DBA script,
        // without USE, GO or GRANT statements.
        $script = file_get_contents(base_path('docs/sql/adms-fingerprint-sync-reviews.sql'));
        $start = strpos($script, "IF OBJECT_ID(N'adms.fingerprint_sync_reviews'");
        $end = strpos($script, 'IF DATABASE_PRINCIPAL_ID', $start ?: 0);
        if ($start === false || $end === false || $end <= $start) throw new RuntimeException('Bundled fingerprint review DDL is invalid.');
        $db->unprepared(substr($script,$start,$end-$start));
    }

    public function down(): void
    {
        throw new RuntimeException('Fingerprint approval history must not be dropped by a rollback. Disable dispatch instead.');
    }
};
