<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            if (! Schema::hasColumn('devices', 'serial_number')) {
                $table->string('serial_number')->nullable()->after('name');
            }

            if (! Schema::hasColumn('devices', 'last_seen')) {
                $table->timestamp('last_seen')->nullable()->after('last_heartbeat');
            }

            if (! Schema::hasColumn('devices', 'raw_config')) {
                $table->json('raw_config')->nullable()->after('last_sync');
            }

            if (! Schema::hasColumn('devices', 'notes')) {
                $table->text('notes')->nullable()->after('raw_config');
            }

            if (! Schema::hasColumn('devices', 'enrolled_faces')) {
                $table->unsignedInteger('enrolled_faces')->nullable()->after('capacity');
            }

            if (! Schema::hasColumn('devices', 'enrolled_fingerprints')) {
                $table->unsignedInteger('enrolled_fingerprints')->nullable()->after('enrolled_faces');
            }
        });

        if (Schema::hasColumn('devices', 'serial_number') && Schema::hasColumn('devices', 'sn')) {
            DB::statement('UPDATE devices SET serial_number = sn WHERE serial_number IS NULL');
        }
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $columns = collect([
                'serial_number',
                'last_seen',
                'raw_config',
                'notes',
                'enrolled_faces',
                'enrolled_fingerprints',
            ])->filter(static fn (string $column): bool => Schema::hasColumn('devices', $column))->values()->all();

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
