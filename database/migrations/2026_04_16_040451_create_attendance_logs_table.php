<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('device_id')->nullable();
            $table->foreignId('employee_id')->nullable();

            $table->string('employee_code')->nullable();

            $table->string('log_type')->default('unknown');
            $table->string('verify_type')->default('unknown');

            $table->timestamp('log_time')->nullable();

            $table->bigInteger('device_log_id')->nullable();

            $table->json('raw_payload')->nullable();

            $table->string('temperature')->nullable();

            $table->string('status')->default('pending');

            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->softDeletes();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
