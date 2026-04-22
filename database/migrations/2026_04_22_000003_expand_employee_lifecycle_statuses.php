<?php

use App\Models\Employee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('employees', 'suspended_at')) {
                $table->timestamp('suspended_at')->nullable()->after('status');
            }

            if (! Schema::hasColumn('employees', 'resigned_at')) {
                $table->timestamp('resigned_at')->nullable()->after('suspended_at');
            }
        });

        $driver = DB::getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement("ALTER TABLE `employees` MODIFY `status` VARCHAR(32) NOT NULL DEFAULT 'ACTIVE'");

        foreach ([
            'active' => Employee::STATUS_ACTIVE,
            'inactive' => Employee::STATUS_INACTIVE,
            'on_leave' => Employee::STATUS_ON_LEAVE,
            'suspended' => Employee::STATUS_SUSPENDED,
            'resigned' => Employee::STATUS_RESIGNED,
            'terminated' => Employee::STATUS_TERMINATED,
            'retired' => Employee::STATUS_RETIRED,
        ] as $legacyStatus => $normalizedStatus) {
            DB::table('employees')->where('status', $legacyStatus)->update(['status' => $normalizedStatus]);
        }

        DB::table('employees')
            ->where('status', Employee::STATUS_SUSPENDED)
            ->whereNull('suspended_at')
            ->update(['suspended_at' => DB::raw('COALESCE(updated_at, created_at, NOW())')]);

        DB::table('employees')
            ->whereIn('status', Employee::resignedStatuses())
            ->whereNull('resigned_at')
            ->update(['resigned_at' => DB::raw('COALESCE(updated_at, created_at, NOW())')]);

        DB::statement("ALTER TABLE `employees` MODIFY `employee_id` VARCHAR(255) NULL");
        DB::statement("ALTER TABLE `employees` MODIFY `uid` VARCHAR(255) NULL");
        DB::statement("ALTER TABLE `employees` MODIFY `sync_uid` VARCHAR(255) NULL");

        DB::statement(
            "ALTER TABLE `employees` MODIFY `status` ENUM(
                'ACTIVE',
                'ON_LEAVE',
                'SUSPENDED',
                'RESIGNED',
                'TERMINATED',
                'RETIRED',
                'INACTIVE'
            ) NOT NULL DEFAULT 'ACTIVE'"
        );
    }

    public function down(): void
    {
        // No-op. This migration releases identifiers for archived employees and
        // extends lifecycle statuses in a way that is not safely reversible.
    }
};
