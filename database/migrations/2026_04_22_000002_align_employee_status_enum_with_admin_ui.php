<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }

        DB::table('employees')
            ->where('status', 'on_leave')
            ->update([
                'status' => 'suspended',
                'updated_at' => now(),
            ]);

    }

    public function down(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }

        DB::table('employees')
            ->where('status', 'suspended')
            ->update([
                'status' => 'on_leave',
                'updated_at' => now(),
            ]);

    }
};
