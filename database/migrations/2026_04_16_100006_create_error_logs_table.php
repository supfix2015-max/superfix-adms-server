<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->nullable()->constrained('devices')->onDelete('set null');
            $table->string('type', 32)->index();
            $table->string('error_code')->nullable();
            $table->text('message');
            $table->string('severity', 32)->default('medium')->index();
            $table->boolean('resolved')->default(false);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['device_id', 'created_at']);
            $table->index(['created_at', 'resolved']);
            $table->index(['severity', 'resolved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_logs');
    }
};
