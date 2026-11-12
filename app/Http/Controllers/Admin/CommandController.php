<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Services\DeviceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommandController extends Controller
{
    public function __construct(
        protected DeviceService $deviceService
    ) {}

    public function index()
    {
        $commands = DeviceCommand::with(['device', 'createdBy'])->latest()->paginate(20);

        return view('admin.commands.index', compact('commands'));
    }

    public function create()
    {
        // An offline device can be selected; it will only receive commands
        // after a future authenticated /iclock/getrequest poll.
        $devices = Device::query()->orderBy('name')->get();

        $availableCommands = [
            'PULL_ATTENDANCE' => 'Pull attendance from device',
            'PULL_EMPLOYEES' => 'Pull user records from device',
            'UPDATE_TIME' => 'Synchronize device date and time',
            'SET_TIMEZONE' => 'Synchronize device time zone',
            'ENABLE_ATTENDANCE' => 'Enable real-time attendance',
            'DISABLE_ATTENDANCE' => 'Disable real-time attendance',
            'RESTART' => 'Restart device',
            'CLEAR_LOGS' => 'Clear device attendance logs',
            'RM_ADMIN_PRIVILEGE' => 'Clear device administrator privileges',
        ];

        $advancedCommands = [
            'SYNC_EMPLOYEES' => 'Send employee information (requires employee workflow)',
            'SYNC_BIOMETRICS' => 'Send biometric template (requires verified template)',
            'ENROLL_FINGERPRINT' => 'Start fingerprint enrollment (requires employee PIN)',
            'ENROLL_FACE' => 'Start face enrollment (requires employee PIN)',
            'SET_ATTENDANCE_MODE' => 'Configure an attendance option',
        ];

        return view('admin.commands.create', compact(
            'devices', 'availableCommands', 'advancedCommands'
        ));
    }

    public function store(Request $request)
    {
        $allowed = [
            'PULL_ATTENDANCE', 'PULL_EMPLOYEES', 'UPDATE_TIME',
            'SET_TIMEZONE', 'ENABLE_ATTENDANCE', 'DISABLE_ATTENDANCE',
            'RESTART', 'CLEAR_LOGS', 'RM_ADMIN_PRIVILEGE',
        ];
        $validated = $request->validate([
            'device_id' => ['required', 'integer', Rule::exists('adms.devices', 'id')],
            'command' => ['required', 'string', Rule::in($allowed)],
            'parameters' => ['nullable', 'array'],
            'parameters.employee_code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9._:-]{1,64}$/'],
            'parameters.start_time' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'parameters.end_time' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'notes' => ['nullable', 'string', 'max:500'],
            'confirm_sensitive' => ['nullable', 'in:yes'],
        ]);

        if (! config('adms_pilot.enabled') || ! config('zkteco.dispatch_commands')) {
            return back()->withInput()->withErrors([
                'command' => 'Device command delivery is disabled. Enable the ADMS reception and command dispatch flags after provisioning and validating the device.',
            ]);
        }

        $sensitive = ['RESTART', 'CLEAR_LOGS', 'DISABLE_ATTENDANCE', 'RM_ADMIN_PRIVILEGE'];
        if (in_array($validated['command'], $sensitive, true)
            && ($validated['confirm_sensitive'] ?? null) !== 'yes') {
            return back()->withInput()->withErrors([
                'confirm_sensitive' => 'Confirm this device-affecting command before queuing it.',
            ]);
        }

        $device = Device::query()->findOrFail($validated['device_id']);
        $serial = trim((string) ($device->serial_number ?: $device->sn));
        $isHistorical = $serial !== '' && \Illuminate\Support\Facades\DB::connection('legacy_sqlsrv')
            ->table('dbo.Device')->where('DeviceSerialNumber', $serial)->exists();
        $isOffice = $serial === 'BZD5261200843';

        if (! $isHistorical && ! $isOffice) {
            return back()->withInput()->withErrors([
                'device_id' => 'Commands are limited to original registered devices and the named office UA300.',
            ]);
        }

        // An explicit token requirement on outbound commands must be configured.
        // Without it, a serial spoof on an HTTP endpoint could receive a command.
        if (! config('zkteco.command_require_token', true)) {
            return back()->withInput()->withErrors([
                'device_id' => 'Command authentication must be enabled before queuing remote device actions.',
            ]);
        }

        if ($validated['command'] === 'UPDATE_TIME') {
            [, $command] = $this->deviceService->queueTimeSync(
                $device->id,
                null,
                $validated['notes'] ?? null
            );
            return redirect()->route('admin.commands.show', $command)
                ->with('success', 'Time synchronization queued. A verified device token is required for dispatch.');
        }

        $parameters = match ($validated['command']) {
            'PULL_EMPLOYEES' => array_filter([
                'employee_code' => $validated['parameters']['employee_code'] ?? null,
                'pin' => $validated['parameters']['employee_code'] ?? null,
            ]),
            'PULL_ATTENDANCE' => array_filter([
                'start_time' => $validated['parameters']['start_time'] ?? null,
                'end_time' => $validated['parameters']['end_time'] ?? null,
            ]),
            default => [],
        };

        if (isset($parameters['start_time'], $parameters['end_time'])
            && strcmp($parameters['end_time'], $parameters['start_time']) < 0) {
            return back()->withInput()->withErrors([
                'parameters.end_time' => 'End date cannot be before start date.',
            ]);
        }

        $command = $this->deviceService->queueDeviceCommand(
            $device->id, $validated['command'],
            $parameters, $validated['notes'] ?? null
        );

        return redirect()->route('admin.commands.show', $command)
            ->with('success', 'Command queued for verified device polling. It has not been executed yet.');
    }

    public function show(DeviceCommand $command)
    {
        $command->load(['device', 'createdBy']);

        return view('admin.commands.show', compact('command'));
    }
}
