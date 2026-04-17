<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDevicesTable extends Migration
{
    public function up()
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();

            // Core identifiers
            $table->string('name')->nullable();
            $table->string('sn')->unique(); // REQUIRED by seeder

            // Network config
            $table->string('ip_address')->nullable();
            $table->integer('port')->nullable();

            // Device metadata
            $table->string('status')->default('active');
            $table->string('device_type')->nullable();

            // Version info
            $table->string('firmware_version')->nullable();
            $table->string('hardware_version')->nullable();

            // Capacity / limits
            $table->integer('capacity')->nullable();

            // Auth / API
            $table->string('api_token')->nullable();

            // Tracking
            $table->timestamp('last_heartbeat')->nullable();
            $table->timestamp('last_sync')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('devices');
    }
}
