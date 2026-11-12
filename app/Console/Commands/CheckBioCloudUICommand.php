<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class CheckBioCloudUICommand extends Command
{
    protected $signature = 'adms:biocloud-ui-check';

    protected $description = 'Read-only check of ADMS Enterprise route integration, source SQL permissions, device license flag and command safety';

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
                "SELECT
                    OBJECT_ID(N'adms.devices', N'U') AS table_id,
                    COL_LENGTH(N'adms.devices', N'requires_license') AS field_length,
                    HAS_PERMS_BY_NAME(N'adms.devices', N'OBJECT', N'ALTER') AS can_alter"
            );
            $visible = $license !== null && $license->table_id !== null;
            $ready = $visible && $license->field_length !== null;
            $results[] = ['Exact operational devices table', $visible
                ? 'OK · Att2000_V4.adms.devices' : 'BLOCKER · adms.devices not visible'];
            $results[] = ['Edit License checkbox column', $ready
                ? 'OK · adms.devices' : 'MIGRATION REQUIRED'];
            if (! $ready) {
                $results[] = ['ALTER permission on adms.devices',
                    $visible && (int) ($license->can_alter ?? 0) === 1
                        ? 'YES · rerun corrected schema-qualified migration'
                        : 'NO · DBA apply docs/sql/adms-devices-requires-license.sql'];
            }
            $fail = $fail || ! $ready || ! $visible;
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
            'admin.dashboard', 'admin.devices.index', 'admin.devices.edit',
            'admin.devices.show', 'admin.directory.index', 'admin.fleet.index',
            'admin.fleet.license', 'admin.fleet.cancel-pending',
            'admin.attendance-reports.show', 'admin.legacy.index',
            'admin.legacy.attendance.export', 'admin.biocloud.system',
            'admin.credentials.index', 'admin.credentials.no-credential',
            'admin.attendance.index', 'admin.commands.index', 'admin.commands.create',
            'admin.commands.store', 'admin.logs.index',
        ] as $name) {
            $ok = Route::has($name);
            $results[] = ["Route {$name}", $ok ? 'OK' : 'MISSING'];
            $fail = $fail || ! $ok;
        }

        // A route's mere existence is insufficient: catch the old UI split
        // that left /admin/devices on the obsolete table while /admin/fleet
        // rendered the new license controls.
        $deviceIndex = Route::getRoutes()->getByName('admin.devices.index');
        $mainPath = $deviceIndex?->uri() === 'admin/devices';
        $results[] = ['Canonical fleet URL', $mainPath
            ? 'OK · /admin/devices' : 'BLOCKER · wrong device page route'];
        $fail = $fail || ! $mainPath;

        if (config('legacy_attendance.browser_enabled', false)) {
            try {
                // Invoke the actual controller, not a hardcoded route string.
                // This is SELECT-only and never sends commands or changes rows.
                $view = app(\App\Http\Controllers\Admin\DeviceController::class)
                    ->index(\Illuminate\Http\Request::create('/admin/devices', 'GET'));
                $joinedFleet = $view instanceof \Illuminate\Contracts\View\View
                    && $view->name() === 'admin.legacy.fleet';
                $results[] = ['Actual /admin/devices controller view', $joinedFleet
                    ? 'OK · combined original + live device fleet' : 'BLOCKER · stale device listing'];
                $fail = $fail || ! $joinedFleet;
            } catch (\Throwable $e) {
                report($e);
                $results[] = ['Actual /admin/devices controller view',
                    'FAILED · check legacy reader / adms connection and log'];
                $fail = true;
            }
        }

        foreach ([
            'resources/views/admin/legacy/fleet.blade.php' => 'Device Fleet',
            'resources/views/admin/legacy/directory.blade.php' => 'Original Directory',
            'resources/views/admin/legacy/attendance.blade.php' => 'Attendance Transactions',
            'resources/views/admin/legacy/report.blade.php' => 'Attendance Reports',
            'resources/views/admin/devices/edit.blade.php' => 'Edit Device',
        ] as $path => $label) {
            $template = base_path($path);
            $raw = is_file($template) ? file_get_contents($template) : false;
            $consistent = is_string($raw) && str_contains($raw, "@extends('admin.layout')")
                && ! str_contains($raw, 'layout_biocloud');
            $results[] = [$label.' UI theme', $consistent
                ? 'OK · existing ADMS Enterprise' : 'BLOCKER · conflicting layout'];
            $fail = $fail || ! $consistent;
        }

        // Fail the preflight if the sidebar regresses to a flat collection
        // or fails to keep commands inside their relevant collapsible group.
        $layout = base_path('resources/views/admin/layout.blade.php');
        $markup = is_file($layout) ? (string) file_get_contents($layout) : '';
        $accordionReady = str_contains($markup, 'class="sidebar-group"')
            && str_contains($markup, 'class="sidebar-subgroup"')
            && str_contains($markup, 'sidebar-subgroup-contents')
            && str_contains($markup, 'aria-current="page"');
        $results[] = ['Enterprise sidebar navigation', $accordionReady
            ? 'OK · collapsible categories + nested actions' : 'BLOCKER · missing nested accordion'];
        $fail = $fail || ! $accordionReady;

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
