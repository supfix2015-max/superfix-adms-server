<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_commands', function (Blueprint $table): void {
            if (! Schema::hasColumn('device_commands', 'sent_at')) {
                $table->timestamp('sent_at')->nullable()->after('status');
            }

            if (! Schema::hasColumn('device_commands', 'acknowledged_at')) {
                $table->timestamp('acknowledged_at')->nullable()->after('executed_at');
            }

            if (! Schema::hasColumn('device_commands', 'command_payload')) {
                $table->text('command_payload')->nullable()->after('parameters');
            }

            if (! Schema::hasColumn('device_commands', 'response_payload')) {
                $table->json('response_payload')->nullable()->after('response');
            }

            if (! Schema::hasColumn('device_commands', 'retry_count')) {
                $table->unsignedInteger('retry_count')->default(0)->after('response_payload');
            }
        });
    }

    public function down(): void
    {
        Schema::table('device_commands', function (Blueprint $table): void {
            $columns = collect([
                'sent_at',
                'acknowledged_at',
                'command_payload',
                'response_payload',
                'retry_count',
            ])->filter(static fn (string $column): bool => Schema::hasColumn('device_commands', $column))
                ->values()
                ->all();

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
