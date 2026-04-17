<?php

namespace App\Repositories;

use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Database\Eloquent\Collection;

class DeviceCommandRepository extends BaseRepository
{
    public function __construct(DeviceCommand $model)
    {
        parent::__construct($model);
    }

    public function findReusableCommand(int $deviceId, string $fingerprint): ?DeviceCommand
    {
        return $this->model->query()
            ->where('device_id', $deviceId)
            ->where('fingerprint', $fingerprint)
            ->whereIn('status', [
                DeviceCommand::STATUS_PENDING,
                DeviceCommand::STATUS_SENT,
                DeviceCommand::STATUS_DONE,
            ])
            ->latest('id')
            ->first();
    }

    public function findNextPending(Device $device): ?DeviceCommand
    {
        return $device->commands()
            ->where('status', DeviceCommand::STATUS_PENDING)
            ->orderBy('created_at')
            ->first();
    }

    public function findRetryableSent(Device $device, int $maxRetries, \Carbon\CarbonInterface $retryAfter): ?DeviceCommand
    {
        return $device->commands()
            ->where('status', DeviceCommand::STATUS_SENT)
            ->whereNotNull('sent_at')
            ->where('sent_at', '<=', $retryAfter)
            ->where('retry_count', '<', $maxRetries)
            ->orderBy('sent_at')
            ->first();
    }

    public function expireCommands(?int $deviceId = null): int
    {
        $query = $this->model->query()
            ->whereIn('status', [DeviceCommand::STATUS_PENDING, DeviceCommand::STATUS_SENT])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());

        if ($deviceId !== null) {
            $query->where('device_id', $deviceId);
        }

        return $query->update([
            'status' => DeviceCommand::STATUS_FAILED,
            'failed_at' => now(),
            'error_message' => 'Command expired before device acknowledgement',
        ]);
    }

    public function markRetryLimitExceeded(?int $deviceId, int $maxRetries): int
    {
        $query = $this->model->query()
            ->where('status', DeviceCommand::STATUS_SENT)
            ->where('retry_count', '>=', $maxRetries);

        if ($deviceId !== null) {
            $query->where('device_id', $deviceId);
        }

        return $query->update([
            'status' => DeviceCommand::STATUS_FAILED,
            'failed_at' => now(),
            'error_message' => 'Command retry limit exceeded',
        ]);
    }

    public function retryableFailures(int $maxRetries): Collection
    {
        return $this->model->query()
            ->where('status', DeviceCommand::STATUS_FAILED)
            ->where('retry_count', '<', $maxRetries)
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderBy('failed_at')
            ->get();
    }
}
