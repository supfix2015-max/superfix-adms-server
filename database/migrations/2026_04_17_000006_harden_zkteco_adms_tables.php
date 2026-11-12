<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('device_commands')
            ->where('status', 'executed')
            ->update(['status' => 'done']);

        Schema::table('device_commands', function (Blueprint $table): void {
            $table->index(['device_id', 'status', 'sent_at'], 'device_commands_dispatch_index');
        });

        $duplicateHashes = DB::table('attendance_logs')
            ->select('payload_hash')
            ->whereNotNull('payload_hash')
            ->groupBy('payload_hash')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('payload_hash');

        foreach ($duplicateHashes as $hash) {
            $ids = DB::table('attendance_logs')
                ->where('payload_hash', $hash)
                ->orderBy('id')
                ->pluck('id');

            if ($ids->count() > 1) {
                DB::table('attendance_logs')
                    ->whereIn('id', $ids->slice(1))
                    ->delete();
            }
        }

        // SQL Server permits only one NULL under a normal UNIQUE constraint.
        // Older/partial events may have NULL hashes, so enforce uniqueness
        // only for non-NULL hashes without modifying the historical DB.
        DB::statement(
            'CREATE UNIQUE INDEX [attendance_logs_payload_hash_unique] '
            .'ON [adms].[attendance_logs] ([payload_hash]) '
            .'WHERE [payload_hash] IS NOT NULL'
        );

        Schema::table('raw_logs', function (Blueprint $table): void {
            $table->index(['endpoint', 'processing_status'], 'raw_logs_endpoint_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('device_commands', function (Blueprint $table): void {
            $table->dropIndex('device_commands_dispatch_index');
        });

        DB::statement('DROP INDEX [attendance_logs_payload_hash_unique] ON [adms].[attendance_logs]');

        Schema::table('raw_logs', function (Blueprint $table): void {
            $table->dropIndex('raw_logs_endpoint_status_index');
        });

        DB::table('device_commands')
            ->where('status', 'done')
            ->update(['status' => 'executed']);
    }
};
