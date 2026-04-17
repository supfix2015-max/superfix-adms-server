<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('attendance_logs', 'uid')) {
                $table->string('uid')->nullable()->after('employee_code');
            }

            if (! Schema::hasColumn('attendance_logs', 'punch_state')) {
                $table->string('punch_state')->default('unknown')->after('log_type');
            }

            if (! Schema::hasColumn('attendance_logs', 'work_code')) {
                $table->string('work_code')->nullable()->after('verify_type');
            }

            if (! Schema::hasColumn('attendance_logs', 'source')) {
                $table->string('source')->default('adms')->after('status');
            }

            if (! Schema::hasColumn('attendance_logs', 'payload_hash')) {
                $table->string('payload_hash', 64)->nullable()->after('device_log_id');
                $table->index('payload_hash');
            }

            $table->index(['device_id', 'log_time']);
            $table->index(['employee_id', 'log_time']);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table): void {
            if (Schema::hasColumn('attendance_logs', 'payload_hash')) {
                $table->dropIndex(['payload_hash']);
            }

            $table->dropIndex(['device_id', 'log_time']);
            $table->dropIndex(['employee_id', 'log_time']);

            $columns = collect(['uid', 'punch_state', 'work_code', 'source', 'payload_hash'])
                ->filter(static fn (string $column): bool => Schema::hasColumn('attendance_logs', $column))
                ->values()
                ->all();

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
