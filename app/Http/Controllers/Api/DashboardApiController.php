<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\ErrorLog;
use App\Models\RawLog;
use Illuminate\Http\JsonResponse;

class DashboardApiController extends Controller
{
    public function summary(): JsonResponse
    {
        $onlineDevices = Device::query()->online()->count();

        return response()->json([
            'devices' => [
                'total' => Device::query()->count(),
                'online' => $onlineDevices,
                'offline' => Device::query()->count() - $onlineDevices,
                'inactive' => Device::query()->where('status', Device::STATUS_INACTIVE)->count(),
            ],
            'attendance' => [
                'today' => AttendanceLog::query()->whereDate('log_time', today())->count(),
                'last_24_hours' => AttendanceLog::query()->where('log_time', '>=', now()->subDay())->count(),
            ],
            'commands' => [
                'pending' => DeviceCommand::query()->where('status', DeviceCommand::STATUS_PENDING)->count(),
                'sent' => DeviceCommand::query()->where('status', DeviceCommand::STATUS_SENT)->count(),
                'done' => DeviceCommand::query()->where('status', DeviceCommand::STATUS_DONE)->count(),
                'failed' => DeviceCommand::query()->where('status', DeviceCommand::STATUS_FAILED)->count(),
            ],
            'logs' => [
                'raw_today' => RawLog::query()->whereDate('created_at', today())->count(),
                'errors_unresolved' => ErrorLog::query()->where('resolved', false)->count(),
            ],
        ]);
    }

    public function devices(): JsonResponse
    {
        $devices = Device::query()
            ->latest('last_seen')
            ->get()
            ->map(static function (Device $device): array {
                return [
                    'id' => $device->id,
                    'name' => $device->name,
                    'serial_number' => $device->serial_number,
                    'status' => $device->status,
                    'online' => $device->isOnline(),
                    'last_seen' => $device->last_seen?->toIso8601String(),
                    'last_sync' => $device->last_sync?->toIso8601String(),
                    'attendance_count_today' => $device->attendanceLogs()
                        ->whereDate('log_time', today())
                        ->count(),
                ];
            })
            ->values();

        return response()->json([
            'devices' => $devices,
        ]);
    }

    public function logs(): JsonResponse
    {
        return response()->json([
            'recent_raw_logs' => RawLog::query()
                ->with('device:id,name,serial_number,sn')
                ->latest()
                ->limit(20)
                ->get(),
            'recent_errors' => ErrorLog::query()
                ->with('device:id,name,serial_number,sn')
                ->latest()
                ->limit(20)
                ->get(),
        ]);
    }
}
