<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('endpoint');
            $table->longText('payload')->nullable();
            $table->json('parsed_data')->nullable();
            $table->string('processing_status')->default('received')->index();
            $table->unsignedInteger('record_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['device_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_logs');
    }
};
