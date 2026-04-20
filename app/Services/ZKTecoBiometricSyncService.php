<?php

namespace App\Services;

class ZKTecoBiometricSyncService extends BiometricTemplateSyncService
{
    public function fetchTemplatesFromDevice(string $deviceIp, string $userId): array
    {
        return parent::fetchTemplatesFromDevice($deviceIp, $userId);
    }

    public function pushTemplatesToDevice(string $deviceIp, string $userId, array $templates, array $options = []): array
    {
        return parent::pushTemplatesToDevice($deviceIp, $userId, $templates, $options);
    }

    public function validateDeviceCompatibility(mixed $sourceModel, mixed $targetModel): array
    {
        return parent::validateDeviceCompatibility($sourceModel, $targetModel);
    }

    public function syncForUser(string $userId, string $sourceDeviceIp, array $targetDeviceIps, array $options = []): array
    {
        return $this->syncUserAcrossDevices($userId, $sourceDeviceIp, $targetDeviceIps, $options);
    }
}
