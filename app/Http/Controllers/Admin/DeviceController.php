<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Employee;
use App\Http\Controllers\Admin\LegacyFleetController;
use App\Repositories\DeviceRepository;
use App\Services\DeviceService;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function __construct(
        protected DeviceService $deviceService,
        protected DeviceRepository $deviceRepository
    ) {}

    public function index(Request $request)
    {
        // Present the combined original-SQL + operational fleet at the
        // established /admin/devices URL. Previously the new fleet view
        // was hidden at /admin/fleet while /admin/devices stayed obsolete.
        if (config('legacy_attendance.browser_enabled', false)) {
            return app(LegacyFleetController::class)->index($request);
        }

        $filters = [
            'search' => $request->input('search'),
            'status' => $request->input('status'),
            'device_type' => $request->input('device_type'),
        ];

        $devices = $this->deviceRepository->paginateWithFilters($filters, 15);

        return view('admin.devices.index', [
            'devices' => $devices,
            'filters' => $filters,
        ]);
    }

    public function create()
    {
        return view('admin.devices.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sn' => 'required|string|unique:devices,sn',
            'ip_address' => 'required|ip',
            'port' => 'integer|between:1,65535',
        ]);

        try {
            $validated['sn'] = $validated['sn'];  // Keep as 'sn' for service
            $device = $this->deviceService->registerDevice($validated, true);

            return redirect()->route('admin.devices.show', $device)
                ->with('success', 'Device registered successfully');
        } catch (\Exception $e) {
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function show(Device $device)
    {
        $device->load([
            'attendanceLogs' => function ($query) {
                $query->with('employee')
                    ->latest('log_time')
                    ->limit(20);
            },
            'rawLogs' => function ($query) {
                $query->latest()
                    ->limit(10);
            },
            'commands' => function ($query) {
                $query->latest()
                    ->limit(20);
            },
        ]);

        $recentEmployees = Employee::query()
            ->where('device_id', $device->id)
            ->latest('updated_at')
            ->limit(10)
            ->get();

        $stats = [
            'attendance_total' => $device->attendanceLogs()->count(),
            'attendance_unmatched' => $device->attendanceLogs()->whereNull('employee_id')->count(),
            'raw_total' => $device->rawLogs()->count(),
            'raw_processed' => $device->rawLogs()->where('processing_status', 'processed')->count(),
            'raw_failed' => $device->rawLogs()->where('processing_status', 'failed')->count(),
            'employee_total' => $device->employees()->count(),
            'unresolved_errors' => $device->errorLogs()->where('resolved', false)->count(),
        ];

        return view('admin.devices.show', [
            'device' => $device,
            'recentEmployees' => $recentEmployees,
            'stats' => $stats,
        ]);
    }

    public function edit(Device $device)
    {
        return view('admin.devices.edit', [
            'device' => $device,
        ]);
    }

    public function update(Request $request, Device $device)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'ip_address' => 'nullable|ip',
            'port' => 'nullable|integer|between:1,65535',
            'requires_license' => 'required|boolean',
        ]);

        // Only the operational adms.devices row is updated; the original
        // dbo.Device serial and identity are immutable in this workflow.
        $column = \Illuminate\Support\Facades\DB::connection('sqlsrv')->selectOne(
            "SELECT COL_LENGTH(N'adms.devices', N'requires_license') AS field_length"
        );
        if ($column === null || $column->field_length === null) {
            // Device alias and endpoint edits remain valid even while DBA
            // prepares the optional licensing flag schema migration.
            unset($validated['requires_license']);
        }
        $this->deviceService->updateDevice($device->id, $validated);

        return redirect()->route(
            config('legacy_attendance.browser_enabled', false)
                ? 'admin.fleet.index' : 'admin.devices.show',
            config('legacy_attendance.browser_enabled', false) ? [] : [$device]
        )->with('success', 'Operational device settings saved. No physical command sent.');
    }

    public function destroy(Device $device)
    {
        $this->deviceService->deleteDevice($device->id);

        return redirect()->route('admin.devices.index')
            ->with('success', 'Device deleted successfully');
    }

    public function refreshToken(Device $device)
    {
        $newToken = $this->deviceService->generateNewToken($device->id);

        return back()->with('success', "New token generated: {$newToken}");
    }

    public function queueCommand(Request $request, Device $device)
    {
        if (! config('adms_pilot.enabled', false)
            || ! config('zkteco.dispatch_commands', false)
            || ! config('zkteco.command_require_token', true)) {
            return back()->withErrors([
                'command' => 'Remote commands require the authenticated device-command pilot and dispatch enabled.',
            ]);
        }

        $validated = $request->validate([
'command' => 'required|string|in:REBOOT,UPDATE_TIME,CLEAR_LOGS,ClearAdmin,ENABLE_ATTENDANCE,PULL_EMPLOYEES,PULL_ATTENDANCE,RM_ADMIN_PRIVILEGE',
        ]);

        if (in_array($validated['command'], ['REBOOT', 'CLEAR_LOGS', 'ClearAdmin', 'RM_ADMIN_PRIVILEGE'], true)
            && $request->input('confirm_sensitive') !== 'yes') {
            return back()->withErrors([
                'confirm_sensitive' => 'This action affects the physical terminal. Confirm it explicitly.',
            ]);
        }

        if ($validated['command'] === 'UPDATE_TIME') {
            [$timezoneCommand, $timeCommand] = $this->deviceService->queueTimeSync($device->id);

            return back()->with(
                'success',
                "Time sync queued for {$device->name} ({$timezoneCommand->id}: timezone, {$timeCommand->id}: date/time)."
            );
        }

        $this->deviceService->queueDeviceCommand($device->id, $validated['command']);

        return back()->with('success', "Command queued for {$device->name}");
    }

    public function pullData(Request $request, Device $device)
    {
        if (! config('adms_pilot.enabled', false)
            || ! config('zkteco.dispatch_commands', false)
            || ! config('zkteco.command_require_token', true)) {
            return back()->withErrors([
                'dataset' => 'Device pulls require authenticated command dispatch. Live attendance reception remains enabled.',
            ]);
        }

        $validated = $request->validate([
            'dataset' => 'required|string|in:employees,attendance',
        ]);

        $command = match ($validated['dataset']) {
            'employees' => $this->deviceService->queuePullEmployees($device->id, notes: 'Pull employees from device'),
            'attendance' => $this->deviceService->queuePullAttendance($device->id, notes: 'Pull attendance from device'),
        };

        return back()->with(
            'success',
            "Pull command queued ({$command->command}). The device must poll /iclock/getrequest and push the returned data back to /iclock/cdata."
        );
    }
}
