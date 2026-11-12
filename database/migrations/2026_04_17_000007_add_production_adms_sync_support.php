<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('device_syncs')) {
            Schema::create('device_syncs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
                $table->string('sync_type', 32)->default('users');
                $table->unsignedBigInteger('version')->default(1);
                $table->string('dataset_hash', 64)->nullable();
                $table->string('status', 32)->default('queued')->index();
                $table->unsignedInteger('total_records')->default(0);
                $table->unsignedInteger('queued_records')->default(0);
                $table->unsignedInteger('processed_records')->default(0);
                $table->unsignedInteger('successful_records')->default(0);
                $table->unsignedInteger('failed_records')->default(0);
                $table->timestamp('sync_started_at')->nullable();
                $table->timestamp('sync_completed_at')->nullable();
                $table->timestamp('last_dispatched_at')->nullable();
                $table->json('sync_metadata')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['device_id', 'sync_type', 'version'], 'device_syncs_device_type_version_unique');
                $table->index(['device_id', 'sync_type', 'status'], 'device_syncs_device_type_status_index');
                $table->index(['device_id', 'dataset_hash'], 'device_syncs_device_hash_index');
            });
        }

        Schema::table('employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('employees', 'sync_uid')) {
                $table->string('sync_uid')->nullable()->after('uid');
            }

            if (! Schema::hasColumn('employees', 'sync_name')) {
                $table->string('sync_name')->nullable()->after('name');
            }

            if (! Schema::hasColumn('employees', 'sync_enabled')) {
                $table->boolean('sync_enabled')->default(false)->after('status');
            }

            if (! Schema::hasColumn('employees', 'sync_validation_errors')) {
                $table->json('sync_validation_errors')->nullable()->after('raw_payload');
            }

            if (! Schema::hasColumn('employees', 'last_sync_validated_at')) {
                $table->timestamp('last_sync_validated_at')->nullable()->after('sync_validation_errors');
            }

            if (! Schema::hasColumn('employees', 'last_synced_payload_hash')) {
                $table->string('last_synced_payload_hash', 64)->nullable()->after('last_sync_validated_at');
            }
        });

        // SQL Server requires NO ACTION here: RESTRICT is invalid SQL
        // Server syntax, and SET NULL creates multiple cascade paths via
        // devices -> device_syncs -> device_commands.
        // Create nullable columns separately from foreign keys so a failed
        // earlier migration can resume without losing its partially added
        // columns or silently skipping the missing constraints.
        Schema::table('device_commands', function (Blueprint $table): void {
            if (! Schema::hasColumn('device_commands', 'device_sync_id')) {
                $table->foreignId('device_sync_id')->nullable()->after('device_id');
            }

            if (! Schema::hasColumn('device_commands', 'employee_id')) {
                $table->foreignId('employee_id')->nullable()->after('device_sync_id');
            }

            if (! Schema::hasColumn('device_commands', 'fingerprint')) {
                $table->string('fingerprint', 64)->nullable()->after('command_payload');
            }

            if (! Schema::hasColumn('device_commands', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('acknowledged_at');
            }

            if (! Schema::hasColumn('device_commands', 'failed_at')) {
                $table->timestamp('failed_at')->nullable()->after('expires_at');
            }
        });

        foreach ([
            'device_commands_device_sync_id_foreign' => ['device_sync_id', 'device_syncs'],
            'device_commands_employee_id_foreign' => ['employee_id', 'employees'],
        ] as $constraintName => [$column, $referencedTable]) {
            $present = DB::selectOne(
                "SELECT COUNT(*) AS total FROM sys.foreign_keys
                 WHERE parent_object_id = OBJECT_ID(N'adms.device_commands')
                   AND name = ?",
                [$constraintName]
            );

            if ((int) ($present->total ?? 0) === 0) {
                // SQL Server defaults to ON DELETE NO ACTION / ON UPDATE NO ACTION.
                // The established FK name is preserved to make retries safe.
                Schema::table('device_commands', function (Blueprint $table) use ($constraintName, $column, $referencedTable): void {
                    $table->foreign($column, $constraintName)
                        ->references('id')->on($referencedTable);
                });
            }
        }

        Schema::table('devices', function (Blueprint $table): void {
            if (! Schema::hasColumn('devices', 'pending_full_resync')) {
                $table->boolean('pending_full_resync')->default(false)->after('last_sync');
            }

            if (! Schema::hasColumn('devices', 'last_reconnected_at')) {
                $table->timestamp('last_reconnected_at')->nullable()->after('pending_full_resync');
            }

            if (! Schema::hasColumn('devices', 'last_user_sync_version')) {
                $table->unsignedBigInteger('last_user_sync_version')->default(0)->after('last_reconnected_at');
            }

            if (! Schema::hasColumn('devices', 'last_user_sync_at')) {
                $table->timestamp('last_user_sync_at')->nullable()->after('last_user_sync_version');
            }
        });

        // SQL Server may commit earlier DDL before a later operation fails.
        // A retry must not fail on already-created indexes.
        $indexMissing = static function (string $table, string $index): bool {
            $found = DB::selectOne(
                "SELECT COUNT(*) AS total FROM sys.indexes
                 WHERE object_id = OBJECT_ID(?) AND name = ?",
                ['adms.'.$table, $index]
            );

            return (int) ($found->total ?? 0) === 0;
        };

        if ($indexMissing('employees', 'employees_sync_enabled_status_index')) {
            Schema::table('employees', static function (Blueprint $table): void {
                $table->index(['sync_enabled', 'status'], 'employees_sync_enabled_status_index');
            });
        }
        if ($indexMissing('employees', 'employees_sync_validated_index')) {
            Schema::table('employees', static function (Blueprint $table): void {
                $table->index(['status', 'last_sync_validated_at'], 'employees_sync_validated_index');
            });
        }
        if ($indexMissing('employees', 'employees_sync_uid_unique')) {
            DB::statement(
                'CREATE UNIQUE INDEX [employees_sync_uid_unique] ON [adms].[employees] ([sync_uid]) '
                .'WHERE [sync_uid] IS NOT NULL'
            );
        }

        foreach ([
            'device_commands_fingerprint_status_index' => ['device_id', 'fingerprint', 'status'],
            'device_commands_expiration_index' => ['status', 'expires_at'],
            'device_commands_sync_status_index' => ['device_sync_id', 'status'],
        ] as $name => $columns) {
            if ($indexMissing('device_commands', $name)) {
                Schema::table('device_commands', static function (Blueprint $table) use ($columns, $name): void {
                    $table->index($columns, $name);
                });
            }
        }

        if ($indexMissing('devices', 'devices_resync_status_index')) {
            Schema::table('devices', static function (Blueprint $table): void {
                $table->index(['pending_full_resync', 'status'], 'devices_resync_status_index');
            });
        }
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropIndex('devices_resync_status_index');
        });

        Schema::table('device_commands', function (Blueprint $table): void {
            $table->dropIndex('device_commands_fingerprint_status_index');
            $table->dropIndex('device_commands_expiration_index');
            $table->dropIndex('device_commands_sync_status_index');
        });

        DB::statement('DROP INDEX [employees_sync_uid_unique] ON [adms].[employees]');
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropIndex('employees_sync_enabled_status_index');
            $table->dropIndex('employees_sync_validated_index');
        });

        Schema::table('devices', function (Blueprint $table): void {
            $columns = collect([
                'pending_full_resync',
                'last_reconnected_at',
                'last_user_sync_version',
                'last_user_sync_at',
            ])->filter(static fn (string $column): bool => Schema::hasColumn('devices', $column))
                ->values()
                ->all();

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('device_commands', function (Blueprint $table): void {
            $columns = collect([
                'device_sync_id',
                'employee_id',
                'fingerprint',
                'expires_at',
                'failed_at',
            ])->filter(static fn (string $column): bool => Schema::hasColumn('device_commands', $column))
                ->values()
                ->all();

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('employees', function (Blueprint $table): void {
            $columns = collect([
                'sync_uid',
                'sync_name',
                'sync_enabled',
                'sync_validation_errors',
                'last_sync_validated_at',
                'last_synced_payload_hash',
            ])->filter(static fn (string $column): bool => Schema::hasColumn('employees', $column))
                ->values()
                ->all();

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::dropIfExists('device_syncs');
    }
};
