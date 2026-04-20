<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Employee;
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
            $device = $this->deviceService->registerDevice($validated);

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
            'ip_address' => 'required|ip',
            'port' => 'integer|between:1,65535',
        ]);

        $this->deviceService->updateDevice($device->id, $validated);

        return redirect()->route('admin.devices.show', $device)
            ->with('success', 'Device updated successfully');
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
        $validated = $request->validate([
'command' => 'required|string|in:REBOOT,UPDATE_TIME,CLEAR_LOGS,ClearAdmin,ENABLE_ATTENDANCE,PULL_EMPLOYEES,PULL_ATTENDANCE,RM_ADMIN_PRIVILEGE',
        ]);

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
