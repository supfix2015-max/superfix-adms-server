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
                $table->timestamp('suspended_at')->nullable();
            }

            if (! Schema::hasColumn('employees', 'resigned_at')) {
                $table->timestamp('resigned_at')->nullable();
            }
        });

        // The SQL Server operational schema uses NVARCHAR status, not an
        // enumerated type, so no DDL conversion is required.
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
            ->update(['suspended_at' => DB::raw('COALESCE(updated_at, created_at, GETDATE())')]);

        DB::table('employees')
            ->whereIn('status', Employee::resignedStatuses())
            ->whereNull('resigned_at')
            ->update(['resigned_at' => DB::raw('COALESCE(updated_at, created_at, GETDATE())')]);
    }

    public function down(): void
    {
        // Historical state normalization is intentionally irreversible.
    }
};
