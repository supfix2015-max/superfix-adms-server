<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Repositories\DeviceRepository;
use Illuminate\Support\Str;

class DeviceService
{
    public function __construct(protected DeviceRepository $repository) {}

    public function registerDevice(array $data): Device
    {
        $serialNumber = $data['serial_number'] ?? $data['sn'] ?? null;
        if (! $serialNumber) {
            throw new \InvalidArgumentException('Device serial number is required');
        }

        $attributes = [
            'serial_number' => $serialNumber,
            'sn' => $serialNumber,
            'name' => $data['name'] ?? "ZKTeco {$serialNumber}",
            'ip_address' => $data['ip_address'] ?? null,
            'port' => $data['port'] ?? config('zkteco.default_device_port', 8000),
            'status' => $data['status'] ?? Device::STATUS_ACTIVE,
            'device_type' => $data['device_type'] ?? null,
            'firmware_version' => $data['firmware_version'] ?? null,
            'hardware_version' => $data['hardware_version'] ?? null,
            'capacity' => $data['capacity'] ?? null,
            'api_token' => $data['api_token'] ?? Str::random(40),
            'raw_config' => $data['raw_config'] ?? null,
        ];

        $device = Device::withTrashed()
            ->where(function ($query) use ($serialNumber): void {
                $query->where('serial_number', $serialNumber)
                    ->orWhere('sn', $serialNumber);
            })
            ->first();

        if (! $device) {
            return Device::query()->create($attributes);
        }

        if ($device->trashed()) {
            $device->restore();
        }

        $device->forceFill([
            ...$attributes,
            'api_token' => $data['api_token'] ?? $device->api_token ?? Str::random(40),
        ])->save();

        return $device->fresh();
    }

    public function updateDevice(int $deviceId, array $data): bool
    {
        if (isset($data['sn']) && ! isset($data['serial_number'])) {
            $data['serial_number'] = $data['sn'];
        }

        return $this->repository->update($deviceId, $data);
    }

    public function recordHeartbeat(int $deviceId): bool
    {
        return $this->repository->update($deviceId, [
            'last_seen' => now(),
            'last_heartbeat' => now(),
            'status' => Device::STATUS_ACTIVE,
        ]);
    }

    public function recordSync(int $deviceId, array $metadata = []): bool
    {
        $device = $this->repository->find($deviceId);
        if (! $device) {
            return false;
        }

        return $device->update([
            'last_sync' => now(),
            'raw_config' => array_merge($device->raw_config ?? [], $metadata),
        ]);
    }

    public function queueDeviceCommand(int $deviceId, string $command, array $parameters = [], ?string $notes = null): DeviceCommand
    {
        $normalizedCommand = match (strtoupper($command)) {
            'REBOOT' => 'RESTART',
            'SYNC' => 'SYNC_EMPLOYEES',
            'PULL_USERS' => 'PULL_EMPLOYEES',
            'PULL_LOGS' => 'PULL_ATTENDANCE',
            default => $command,
        };

        $protocolCommand = ! in_array($normalizedCommand, DeviceCommand::KNOWN_COMMANDS, true)
            ? trim($normalizedCommand)
            : null;

        return DeviceCommand::query()->create([
            'device_id' => $deviceId,
            'command' => $normalizedCommand,
            'command_payload' => $protocolCommand,
            'parameters' => array_filter(array_merge($parameters, [
                'notes' => $notes,
            ]), static fn ($value) => $value !== null && $value !== ''),
            'status' => DeviceCommand::STATUS_PENDING,
            'created_by' => auth()->id(),
        ]);
    }

    public function queuePullEmployees(int $deviceId, ?string $employeeCode = null, ?string $notes = null): DeviceCommand
    {
        return $this->queueDeviceCommand(
            $deviceId,
            'PULL_EMPLOYEES',
            array_filter([
                'employee_code' => $employeeCode,
                'pin' => $employeeCode,
            ], static fn ($value) => $value !== null && $value !== ''),
            $notes
        );
    }

    public function queuePullAttendance(int $deviceId, ?string $startTime = null, ?string $endTime = null, ?string $notes = null): DeviceCommand
    {
        return $this->queueDeviceCommand(
            $deviceId,
            'PULL_ATTENDANCE',
            array_filter([
                'start_time' => $startTime,
                'end_time' => $endTime,
            ], static fn ($value) => $value !== null && $value !== ''),
            $notes
        );
    }

    public function getDevice(int $deviceId): ?Device
    {
        return $this->repository->find($deviceId);
    }

    public function getDeviceBySN(string $serialNumber): ?Device
    {
        return $this->repository->findBySN($serialNumber);
    }

    public function getDeviceByToken(string $token): ?Device
    {
        return $this->repository->findByToken($token);
    }

    public function getAllDevices()
    {
        return $this->repository->all();
    }

    public function getActiveDevices()
    {
        return $this->repository->getActive();
    }

    public function getOnlineDevices()
    {
        return $this->repository->getOnline();
    }

    public function markDeviceOnline(int $deviceId): bool
    {
        return $this->recordHeartbeat($deviceId);
    }

    public function markDeviceOffline(int $deviceId): bool
    {
        return $this->repository->update($deviceId, [
            'status' => Device::STATUS_OFFLINE,
        ]);
    }

    public function deactivateDevice(int $deviceId): bool
    {
        return $this->repository->update($deviceId, [
            'status' => Device::STATUS_INACTIVE,
        ]);
    }

    public function generateNewToken(int $deviceId): ?string
    {
        $token = Str::random(40);
        if ($this->repository->update($deviceId, ['api_token' => $token])) {
            return $token;
        }

        return null;
    }

    public function getDeviceStatistics(): array
    {
        return $this->repository->getStatistics();
    }

    public function deleteDevice(int $deviceId): bool
    {
        return $this->repository->delete($deviceId);
    }

    public function restoreDevice(int $deviceId): bool
    {
        return $this->repository->restore($deviceId);
    }
}
