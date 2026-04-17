<?php

namespace App\Repositories;

use App\Models\DeviceSync;

class DeviceSyncRepository extends BaseRepository
{
    public function __construct(DeviceSync $model)
    {
        parent::__construct($model);
    }

    public function nextVersionForDevice(int $deviceId, string $syncType = DeviceSync::TYPE_USERS): int
    {
        return (int) $this->model->query()
            ->where('device_id', $deviceId)
            ->where('sync_type', $syncType)
            ->max('version') + 1;
    }

    public function findReusableBatch(int $deviceId, string $syncType, string $datasetHash): ?DeviceSync
    {
        return $this->model->query()
            ->where('device_id', $deviceId)
            ->where('sync_type', $syncType)
            ->where('dataset_hash', $datasetHash)
            ->whereIn('status', [
                DeviceSync::STATUS_QUEUED,
                DeviceSync::STATUS_DISPATCHING,
                DeviceSync::STATUS_COMPLETED,
            ])
            ->latest('id')
            ->first();
    }
}
