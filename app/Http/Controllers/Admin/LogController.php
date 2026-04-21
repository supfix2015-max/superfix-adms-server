<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeviceCommand;
use App\Models\ErrorLog;
use App\Models\RawLog;
use Illuminate\Http\Request;

class LogController extends Controller
{
    public function index(Request $request)
    {
        $activeTab = $request->string('tab')->value();
        $activeTab = in_array($activeTab, ['raw', 'error', 'commands'], true) ? $activeTab : 'raw';
        $perPage = 25;

        return view('admin.logs.index', [
            'activeTab' => $activeTab,
            'rawLogs' => RawLog::query()
                ->with('device')
                ->latest()
                ->paginate($perPage, ['*'], 'raw_page'),
            'rawStats' => [
                'today' => RawLog::query()->whereDate('created_at', today())->count(),
                'pending' => RawLog::query()->where('processing_status', 'processing')->count(),
            ],
            'errorLogs' => ErrorLog::query()
                ->with('device')
                ->latest()
                ->paginate($perPage, ['*'], 'error_page'),
            'errorStats' => [
                'today' => ErrorLog::query()->whereDate('created_at', today())->count(),
                'unresolved' => ErrorLog::query()->where('resolved', false)->count(),
            ],
            'commands' => DeviceCommand::query()
                ->with(['device', 'creator'])
                ->latest()
                ->paginate($perPage, ['*'], 'command_page'),
        ]);
    }

    public function rawPayload($id)
    {
        $log = RawLog::query()->findOrFail($id);

        return response($log->payload)->header('Content-Type', 'text/plain');
    }
}
