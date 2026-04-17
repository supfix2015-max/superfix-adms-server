<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Services\DeviceService;
use Illuminate\Http\Request;

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
        $devices = Device::where('status', 'active')->get();

        return view('admin.commands.create', compact('devices'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_id' => 'required|exists:devices,id',
            'command' => 'required|string|max:255',
            'parameters' => 'nullable|array',
            'notes' => 'nullable|string|max:500',
        ]);

        $command = $this->deviceService->queueDeviceCommand(
            $validated['device_id'],
            $validated['command'],
            $validated['parameters'] ?? [],
            $validated['notes'] ?? null
        );

        return redirect()->route('admin.commands.show', $command)
            ->with('success', 'Command queued successfully');
    }

    public function show(DeviceCommand $command)
    {
        $command->load(['device', 'createdBy']);

        return view('admin.commands.show', compact('command'));
    }
}
