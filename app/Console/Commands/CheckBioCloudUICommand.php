<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class CheckBioCloudUICommand extends Command
{
    protected $signature = 'adms:biocloud-ui-check';

    protected $description = 'Read-only check of BioCloud UI routes, source SQL privileges, device license field and command safety';

    public function handle(): int
    {
        $results = [];
        $fail = false;

        try {
            $db = DB::connection('sqlsrv');
            $ctx = $db->selectOne('SELECT DB_NAME() AS db, SCHEMA_NAME() AS schema_name');
            $correct = strcasecmp((string) ($ctx->db ?? ''), 'Att2000_V4') === 0
                && strcasecmp((string) ($ctx->schema_name ?? ''), 'adms') === 0;
            $results[] = ['Application connection', $correct ? 'OK · Att2000_V4.adms' : 'BLOCKER'];
            $fail = $fail || ! $correct;

            $license = $db->selectOne(
                "SELECT COL_LENGTH(N'adms.devices', N'requires_license') AS field_length"
            );
            $ready = $license !== null && $license->field_length !== null;
            $results[] = ['Edit License checkbox column', $ready ? 'OK · adms.devices' : 'MIGRATION REQUIRED'];
            $fail = $fail || ! $ready;
        } catch (\Throwable $e) {
            $results[] = ['Application SQL connection', 'FAILED: '.$e->getMessage()];
            $fail = true;
        }

        try {
            $original = DB::connection('legacy_sqlsrv');
            foreach (['Employee', 'Device', 'Attendance'] as $table) {
                $original->table('dbo.'.$table)->limit(1)->get(['Id']);
                $results[] = ["Source dbo.{$table} SELECT", 'OK'];
            }

            foreach (['Department', 'Departments', 'Area', 'Areas'] as $optional) {
                $exists = (int) ($original->selectOne(
                    "SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME = ?", [$optional]
                )->total ?? 0) !== 0;
                if ($exists) {
                    try {
                        $original->table('dbo.'.$optional)->limit(1)->get();
                        $results[] = ["Source dbo.{$optional}", 'OK · readable'];
                    } catch (\Throwable) {
                        $results[] = ["Source dbo.{$optional}", 'LIMITED · grant SELECT for names'];
                    }
                }
            }
        } catch (\Throwable $e) {
            $results[] = ['Original SQL read connection', 'FAILED: '.$e->getMessage()];
            $fail = true;
        }

        foreach ([
            'admin.biocloud.home', 'admin.directory.index', 'admin.fleet.index',
            'admin.fleet.license', 'admin.attendance-reports.show',
            'admin.legacy.index', 'admin.commands.store',
        ] as $name) {
            $ok = Route::has($name);
            $results[] = ["Route {$name}", $ok ? 'OK' : 'MISSING'];
            $fail = $fail || ! $ok;
        }

        $results[] = ['Command dispatch', config('zkteco.dispatch_commands', false)
            ? 'ENABLED · validate device token delivery before fleet rollout'
            : 'LOCKED · safe before fleet onboarding'];
        $results[] = ['Per-device command token', config('zkteco.command_require_token', true)
            ? 'REQUIRED' : 'BLOCKER · tokenless commands forbidden'];
        $fail = $fail || ! config('zkteco.command_require_token', true);
        $results[] = ['Time-only registered devices', config('zkteco.legacy_time_only_all_registered', false)
            ? 'ENABLED · existing hourly clock sync preserved' : 'Check fleet clock configuration'];

        $this->table(['Read-only UI readiness check', 'Result'], $results);
        $this->warn('No database rows, device state, commands or configuration were changed.');
        return $fail ? self::FAILURE : self::SUCCESS;
    }
}
