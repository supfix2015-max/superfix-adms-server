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
        $type = $request->get('type', 'raw');

        return view('admin.logs.index', [
            'type' => $type,
            'rawLogs' => RawLog::query()->with('device')->latest()->limit(50)->get(),
            'rawStats' => [
                'today' => RawLog::query()->whereDate('created_at', today())->count(),
                'pending' => RawLog::query()->where('processing_status', 'processing')->count(),
            ],
            'errorLogs' => ErrorLog::query()->with('device')->latest()->limit(50)->get(),
            'errorStats' => [
                'today' => ErrorLog::query()->whereDate('created_at', today())->count(),
                'unresolved' => ErrorLog::query()->where('resolved', false)->count(),
            ],
            'commands' => DeviceCommand::query()->with(['device', 'creator'])->latest()->limit(50)->get(),
        ]);
    }

    public function rawPayload($id)
    {
        $log = RawLog::query()->findOrFail($id);

        return response($log->payload)->header('Content-Type', 'text/plain');
    }
}
