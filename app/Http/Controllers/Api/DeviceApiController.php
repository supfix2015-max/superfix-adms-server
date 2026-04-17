<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DevicePingRequest;
use App\Http\Requests\DeviceRegisterRequest;
use App\Jobs\ProcessAttendanceLogJob;
use App\Jobs\StoreRawLogJob;
use App\Services\ADMSPayloadService;
use App\Services\DeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceApiController extends Controller
{
    public function __construct(
        protected DeviceService $deviceService,
        protected ADMSPayloadService $payloadService
    ) {}

    /**
     * Register device
     * POST /api/device/register
     */
    public function register(DeviceRegisterRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            // Check if device already exists by serial number
            $device = $this->deviceService->getDeviceBySN($data['sn']);

            if ($device) {
                // Update existing device
                $this->deviceService->updateDevice($device->id, $data);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Device updated',
                    'device' => $device->refresh(),
                ]);
            }

            // Create new device
            $device = $this->deviceService->registerDevice($data);

            return response()->json([
                'status' => 'success',
                'message' => 'Device registered successfully',
                'device' => $device,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Device heartbeat/ping
     * POST /api/device/ping
     */
    public function ping(DevicePingRequest $request): JsonResponse
    {
        try {
            $token = $request->input('token') ?? $request->bearerToken();
            $device = $this->deviceService->getDeviceByToken($token);

            if (! $device) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid device token',
                ], 401);
            }

            // Record heartbeat
            $this->deviceService->recordHeartbeat($device->id);

            return response()->json([
                'status' => 'ok',
                'timestamp' => now()->toDateTimeString(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload device logs / attendance
     * POST /api/device/logs
     */
    public function logs(Request $request): JsonResponse
    {
        try {
            $token = $request->input('token') ?? $request->bearerToken();
            $device = $this->deviceService->getDeviceByToken($token);

            if (! $device) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid device token',
                ], 401);
            }

            $payload = $request->getContent();
            $parsed = $this->payloadService->parsePayload($payload);

            // Validate payload
            $validation = $this->payloadService->validatePayload($parsed);
            if (! $validation['valid']) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid payload',
                    'errors' => $validation['errors'],
                ], 422);
            }

            // Store raw log
            StoreRawLogJob::dispatch($device->id, '/api/device/logs', $payload);

            // Parse attendance logs
            $logs = $this->payloadService->parseAttendanceLogs($parsed);

            foreach ($logs as $logData) {
                ProcessAttendanceLogJob::dispatch($device->id, $logData);
            }

            // Record sync
            $this->deviceService->recordSync($device->id, $parsed);

            return response()->json([
                'status' => 'ok',
                'message' => 'Logs received',
                'count' => count($logs),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send commands to device
     * POST /api/device/command
     */
    public function command(Request $request): JsonResponse
    {
        try {
            $token = $request->input('token') ?? $request->bearerToken();
            $device = $this->deviceService->getDeviceByToken($token);

            if (! $device) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid device token',
                ], 401);
            }

            // This would process device commands
            // For now, return OK
            return response()->json([
                'status' => 'ok',
                'message' => 'Command received',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Health check
     * GET /api/health
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toDateTimeString(),
            'uptime' => app()->make('request')->server('REQUEST_TIME') ? (time() - $_SERVER['REQUEST_TIME']) : 0,
        ]);
    }
}
