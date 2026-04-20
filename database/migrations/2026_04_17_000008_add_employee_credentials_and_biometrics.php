<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('employees', 'device_password')) {
                $table->string('device_password', 64)->nullable()->after('card_no');
            }

            if (! Schema::hasColumn('employees', 'device_privilege')) {
                $table->unsignedTinyInteger('device_privilege')->nullable()->after('device_password');
            }

            if (! Schema::hasColumn('employees', 'group_code')) {
                $table->string('group_code', 32)->nullable()->after('device_privilege');
            }

            if (! Schema::hasColumn('employees', 'timezone_code')) {
                $table->string('timezone_code', 32)->nullable()->after('group_code');
            }

            if (! Schema::hasColumn('employees', 'verification_mode')) {
                $table->string('verification_mode', 32)->nullable()->after('timezone_code');
            }

            if (! Schema::hasColumn('employees', 'vice_card_no')) {
                $table->string('vice_card_no', 64)->nullable()->after('verification_mode');
            }

            if (! Schema::hasColumn('employees', 'valid_from')) {
                $table->string('valid_from', 32)->nullable()->after('vice_card_no');
            }

            if (! Schema::hasColumn('employees', 'valid_until')) {
                $table->string('valid_until', 32)->nullable()->after('valid_from');
            }
        });

        if (! Schema::hasTable('employee_biometrics')) {
            Schema::create('employee_biometrics', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
                $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
                $table->string('employee_code', 64)->index();
                $table->string('biometric_type', 32)->index();
                $table->string('template_index', 32)->nullable();
                $table->string('file_name')->nullable();
                $table->boolean('is_valid')->nullable();
                $table->unsignedInteger('data_size')->nullable();
                $table->longText('template_data')->nullable();
                $table->string('sync_key', 64)->unique();
                $table->json('raw_payload')->nullable();
                $table->timestamps();

                $table->index(['device_id', 'employee_code', 'biometric_type'], 'employee_biometrics_lookup_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_biometrics');

        Schema::table('employees', function (Blueprint $table): void {
            $columns = collect([
                'device_password',
                'device_privilege',
                'group_code',
                'timezone_code',
                'verification_mode',
                'vice_card_no',
                'valid_from',
                'valid_until',
            ])->filter(static fn (string $column): bool => Schema::hasColumn('employees', $column))
                ->values()
                ->all();

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
