<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('deleted_device_serials')) {
            return;
        }

        Schema::create('deleted_device_serials', function (Blueprint $table): void {
            $table->id();
            $table->string('serial_number', 64)->unique();
            $table->string('device_name')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deleted_device_serials');
    }
};
