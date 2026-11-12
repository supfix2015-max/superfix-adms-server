<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $identity = DB::connection('sqlsrv')->selectOne(
            'SELECT DB_NAME() AS db, SCHEMA_NAME() AS schema_name'
        );
        if (strcasecmp((string) ($identity->db ?? ''), 'Att2000_V4') !== 0
            || strcasecmp((string) ($identity->schema_name ?? ''), 'adms') !== 0) {
            throw new \RuntimeException('Refusing to modify anything outside Att2000_V4.adms.');
        }

        if (! Schema::hasColumn('devices', 'requires_license')) {
            Schema::table('devices', function (Blueprint $table): void {
                $table->boolean('requires_license')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('devices', 'requires_license')) {
            Schema::table('devices', function (Blueprint $table): void {
                $table->dropColumn('requires_license');
            });
        }
    }
};
