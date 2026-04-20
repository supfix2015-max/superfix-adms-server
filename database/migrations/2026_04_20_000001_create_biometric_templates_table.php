<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->unsignedTinyInteger('finger_index')->nullable();
            $table->string('template_type', 32)->index();
            $table->string('template_index', 32)->nullable();
            $table->unsignedInteger('data_size')->nullable();
            $table->string('template_hash', 64)->index();
            $table->binary('template_data')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['employee_id', 'device_id', 'template_type', 'template_index'],
                'biometric_templates_source_unique'
            );
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE biometric_templates MODIFY template_data LONGBLOB NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_templates');
    }
};
