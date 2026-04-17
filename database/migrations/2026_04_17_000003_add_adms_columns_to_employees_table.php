<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('employees', 'device_id')) {
                $table->unsignedBigInteger('device_id')->nullable()->after('employee_id');
                $table->index('device_id');
            }

            if (! Schema::hasColumn('employees', 'uid')) {
                $table->string('uid')->nullable()->after('device_id');
                $table->index('uid');
            }

            if (! Schema::hasColumn('employees', 'card_no')) {
                $table->string('card_no')->nullable()->after('uid');
                $table->index('card_no');
            }

            if (! Schema::hasColumn('employees', 'raw_payload')) {
                $table->json('raw_payload')->nullable()->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            if (Schema::hasColumn('employees', 'device_id')) {
                $table->dropIndex(['device_id']);
            }

            if (Schema::hasColumn('employees', 'uid')) {
                $table->dropIndex(['uid']);
            }

            if (Schema::hasColumn('employees', 'card_no')) {
                $table->dropIndex(['card_no']);
            }

            $columns = collect(['device_id', 'uid', 'card_no', 'raw_payload'])
                ->filter(static fn (string $column): bool => Schema::hasColumn('employees', $column))
                ->values()
                ->all();

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
