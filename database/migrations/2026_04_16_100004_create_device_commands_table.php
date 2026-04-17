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
        Schema::create('device_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->onDelete('cascade');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->enum('command', [
                'RESTART',
                'CLEAR_LOGS',
                'SET_TIME',
                'UPDATE_TIME',
                'ENABLE_ATTENDANCE',
                'DISABLE_ATTENDANCE',
                'SET_ATTENDANCE_MODE',
                'SYNC_EMPLOYEES',
            ])->index();
            $table->json('parameters')->nullable();
            $table->enum('status', ['pending', 'sent', 'executed', 'failed'])->default('pending')->index();
            $table->timestamp('executed_at')->nullable();
            $table->string('response')->nullable();
            $table->string('error_message')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Indexes for performance
            $table->index(['device_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index(['device_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_commands');
    }
};
