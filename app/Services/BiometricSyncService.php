<?php

namespace App\Services;

use App\Jobs\SyncBiometricTemplate;
use App\Models\BiometricTemplate;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeBiometric;
use App\Models\ErrorLog;
use App\Models\User;
use App\Notifications\AdminBiometricSyncFailureNotification;
use Illuminate\Support\Collection;

class BiometricSyncService
{
    private const LEGACY_CMD_USERTEMP_RRQ = 0x09;

    private const LEGACY_CMD_USERTEMP_WRQ = 0x0A;

    public function __construct(
        protected CommandService $commandService,
    ) {}

    public function syncEmployeeTemplates(Employee $employee, array $options = []): array
    {
        $options = array_merge([
            'source_device' => null,
            'sync_keys' => [],
            'prefer_udp' => true,
            'overwrite' => true,
        ], $options);

        $employee->loadMissing('device');

        $sourceDevice = $options['source_device'] instanceof Device
            ? $options['source_device']
            : $this->resolveSourceDevice($employee);

        if (! $sourceDevice) {
            throw new \RuntimeException("Employee {$employee->employee_id} has no source device for biometric sync.");
        }

        $templates = $this->fetchTemplateFromDevice($sourceDevice, $employee, [
            'prefer_udp' => (bool) $options['prefer_udp'],
            'sync_keys' => (array) $options['sync_keys'],
        ]);

        $targets = Device::query()
            ->active()
            ->whereKeyNot($sourceDevice->id)
            ->orderBy('name')
            ->get();

        $summary = [
            'employee_id' => $employee->employee_id,
            'source_device' => $sourceDevice->ip_address,
            'template_count' => $templates->count(),
            'jobs_dispatched' => 0,
            'skipped' => 0,
            'warnings' => [],
        ];

        foreach ($targets as $target) {
            $compatibility = $this->validateDeviceCompatibility($sourceDevice, $target);

            foreach ($templates as $template) {
                $rule = $template->template_type === BiometricTemplate::TYPE_FACE
                    ? $compatibility['face']
                    : $compatibility['fingerprint'];

                if (! ($rule['compatible'] ?? false)) {
                    $summary['skipped']++;
                    $reason = (string) ($rule['reason'] ?? 'Template type is not compatible with the target device.');
                    $this->flagSyncFailure($template, $target, $reason);
                    continue;
                }

                SyncBiometricTemplate::dispatch($template->id, $target->id, (bool) $options['overwrite'])
                    ->onQueue('device-syncs');

                $summary['jobs_dispatched']++;
            }

            foreach ((array) ($compatibility['warnings'] ?? []) as $warning) {
                $summary['warnings'][] = "{$target->name}: {$warning}";
            }
        }

        $summary['warnings'] = array_values(array_unique($summary['warnings']));

        return $summary;
    }

    public function fetchTemplateFromDevice(Device|string $device, Employee $employee, array $options = []): Collection
    {
        $options = array_merge([
            'prefer_udp' => true,
            'sync_keys' => [],
        ], $options);

        $sourceDevice = $device instanceof Device
            ? $device
            : $this->resolveDeviceByIp($device);

        $rows = collect();
        if ($options['prefer_udp']) {
            $rows = $this->fetchTemplatesViaUdp($sourceDevice, $employee);
        }

        if ($rows->isEmpty()) {
            $rows = $this->fetchTemplatesFromAdmsStore($sourceDevice, $employee, (array) $options['sync_keys']);
        }

        if ($rows->isEmpty()) {
            throw new \RuntimeException(
                "No fingerprint or face templates are available for employee {$employee->employee_id} on {$sourceDevice->name}."
            );
        }

        $persisted = [];
        foreach ($rows as $row) {
            $persisted[] = $this->persistEncryptedTemplate($employee, $sourceDevice, $row);
        }

        return collect($persisted)->values();
    }

    public function pushTemplateToDevice(Device|string $device, BiometricTemplate $template, bool $overwrite = true): array
    {
        $targetDevice = $device instanceof Device
            ? $device
            : $this->resolveDeviceByIp($device);

        $template->loadMissing('employee', 'device');

        $employee = $template->employee;
        $sourceDevice = $template->device;

        if (! $employee || ! $sourceDevice) {
            throw new \RuntimeException('Biometric template is missing its employee or source device relation.');
        }

        $compatibility = $this->validateDeviceCompatibility($sourceDevice, $targetDevice);
        $rule = $template->template_type === BiometricTemplate::TYPE_FACE
            ? $compatibility['face']
            : $compatibility['fingerprint'];

        if (! ($rule['compatible'] ?? false)) {
            throw new \RuntimeException((string) ($rule['reason'] ?? 'Template type is not compatible with the target device.'));
        }

        $client = $this->makeUdpClient($targetDevice);
        if ($client) {
            return $this->pushTemplateViaUdp($client, $targetDevice, $employee, $template, $overwrite);
        }

        return $this->queueAdmsTemplatePush($targetDevice, $employee, $template, $overwrite);
    }

    public function validateDeviceCompatibility(mixed $sourceModel, mixed $targetModel): array
    {
        $source = $this->normalizeModelDescriptor($sourceModel);
        $target = $this->normalizeModelDescriptor($targetModel);

        $warnings = [];
        $fingerprintReason = null;
        $faceReason = null;

        if (! $source['fingerprint_capable'] || ! $target['fingerprint_capable']) {
            $fingerprintReason = 'One of the devices is not fingerprint capable.';
        }

        if (! $source['face_capable'] || ! $target['face_capable']) {
            $faceReason = 'Face templates require both devices to expose a compatible face engine.';
        }

        if ($source['face_capable'] && ! $target['face_capable']) {
            $warnings[] = "Face templates from {$source['label']} cannot be transferred to fingerprint-only {$target['label']} terminals.";
        }

        if ($source['platform'] && $target['platform'] && $source['platform'] !== $target['platform']) {
            $warnings[] = "Platform mismatch detected ({$source['platform']} -> {$target['platform']}).";
            $faceReason ??= "Platform mismatch for face template transfer ({$source['platform']} -> {$target['platform']}).";
        }

        if ($source['finger_algorithm'] && $target['finger_algorithm'] && $source['finger_algorithm'] !== $target['finger_algorithm']) {
            $warnings[] = "Fingerprint algorithm mismatch ({$source['finger_algorithm']} -> {$target['finger_algorithm']}).";
        }

        if ($source['face_algorithm'] && $target['face_algorithm'] && $source['face_algorithm'] !== $target['face_algorithm']) {
            $faceReason ??= "Face algorithm mismatch ({$source['face_algorithm']} -> {$target['face_algorithm']}).";
        }

        if ($source['family'] && $target['family'] && $source['family'] !== $target['family']) {
            $warnings[] = "Device families differ ({$source['family']} -> {$target['family']}); direct verification should be physically tested.";
        }

        return [
            'source_model' => $source['label'],
            'target_model' => $target['label'],
            'fingerprint' => [
                'compatible' => $fingerprintReason === null,
                'reason' => $fingerprintReason,
            ],
            'face' => [
                'compatible' => $faceReason === null,
                'reason' => $faceReason,
            ],
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    public function flagSyncFailure(BiometricTemplate $template, Device $targetDevice, string $reason): void
    {
        $template->loadMissing('employee');

        $employee = $template->employee;
        if (! $employee) {
            return;
        }

        ErrorLog::query()->firstOrCreate(
            [
                'device_id' => $targetDevice->id,
                'type' => 'queue_error',
                'error_code' => 'BIOMETRIC_SYNC_FAILED',
                'message' => sprintf(
                    'Biometric sync failed for employee %s on device %s. Type=%s. Reason=%s.',
                    $employee->employee_id,
                    $targetDevice->name,
                    $template->template_type,
                    $reason,
                ),
            ],
            [
                'severity' => 'high',
                'resolved' => false,
            ],
        );

        $admins = User::query()
            ->admins()
            ->active()
            ->whereNotNull('email')
            ->get();

        foreach ($admins as $admin) {
            try {
                $admin->notify(new AdminBiometricSyncFailureNotification($employee, $targetDevice, $template, $reason));
            } catch (\Throwable) {
                // Notification delivery must not interrupt sync recovery.
            }
        }
    }

    private function fetchTemplatesViaUdp(Device $device, Employee $employee): Collection
    {
        $client = $this->makeUdpClient($device);
        if (! $client) {
            return collect();
        }

        $pin = $this->resolveNumericPin($employee);
        if ($pin === null) {
            return collect();
        }

        $templates = collect();

        try {
            $this->connectUdpClient($client);
            $this->disableUdpDeviceIfSupported($client);

            if (method_exists($client, 'getUserTemplate')) {
                for ($fingerIndex = 0; $fingerIndex <= 9; $fingerIndex++) {
                    try {
                        $rawTemplate = $client->getUserTemplate((int) $pin, $fingerIndex);
                    } catch (\Throwable) {
                        continue;
                    }

                    $normalized = $this->normalizeUdpFingerprintTemplate($rawTemplate, $fingerIndex);
                    if ($normalized) {
                        $templates->push($normalized);
                    }
                }
            }

            if (method_exists($client, 'getFaceData')) {
                try {
                    $faceTemplates = (array) $client->getFaceData((int) $pin);
                } catch (\Throwable) {
                    $faceTemplates = [];
                }

                foreach ($faceTemplates as $templateIndex => $payload) {
                    $plainTemplate = is_array($payload)
                        ? (string) ($payload['template'] ?? '')
                        : (string) $payload;

                    if ($plainTemplate === '') {
                        continue;
                    }

                    $templates->push([
                        'template_type' => BiometricTemplate::TYPE_FACE,
                        'finger_index' => null,
                        'template_index' => (string) $templateIndex,
                        'template_data' => $plainTemplate,
                        'data_size' => is_array($payload) ? (int) ($payload['size'] ?? strlen($plainTemplate)) : strlen($plainTemplate),
                        'metadata' => [
                            'source' => 'udp',
                            'command' => 'getFaceData',
                        ],
                    ]);
                }
            }
        } finally {
            $this->enableUdpDeviceIfSupported($client);
            $this->disconnectUdpClientIfSupported($client);
        }

        return $templates->values();
    }

    private function fetchTemplatesFromAdmsStore(Device $device, Employee $employee, array $syncKeys): Collection
    {
        return EmployeeBiometric::query()
            ->where('device_id', $device->id)
            ->where('employee_id', $employee->id)
            ->when($syncKeys !== [], function ($query) use ($syncKeys): void {
                $query->whereIn('sync_key', $syncKeys);
            })
            ->whereIn('biometric_type', [
                EmployeeBiometric::TYPE_FINGERPRINT,
                EmployeeBiometric::TYPE_FACE,
            ])
            ->orderByDesc('updated_at')
            ->get()
            ->map(function (EmployeeBiometric $biometric): ?array {
                $plainTemplate = trim((string) ($biometric->template_data ?? ''));
                if ($plainTemplate === '') {
                    return null;
                }

                return [
                    'template_type' => strtolower((string) $biometric->biometric_type),
                    'finger_index' => $biometric->biometric_type === EmployeeBiometric::TYPE_FINGERPRINT
                        ? (int) ($biometric->template_index ?? 0)
                        : null,
                    'template_index' => $biometric->template_index ?: ($biometric->biometric_type === EmployeeBiometric::TYPE_FACE ? '50' : '0'),
                    'template_data' => $plainTemplate,
                    'data_size' => $biometric->data_size ?: strlen($plainTemplate),
                    'metadata' => is_array($biometric->raw_payload) ? $biometric->raw_payload : [],
                ];
            })
            ->filter()
            ->unique(static fn (array $template): string => sha1(json_encode([
                'template_type' => $template['template_type'],
                'template_index' => $template['template_index'],
                'template_hash' => sha1((string) $template['template_data']),
            ], JSON_THROW_ON_ERROR)))
            ->values();
    }

    private function persistEncryptedTemplate(Employee $employee, Device $device, array $template): BiometricTemplate
    {
        $plainTemplate = (string) ($template['template_data'] ?? '');
        $templateIndex = (string) ($template['template_index'] ?? ($template['finger_index'] ?? 0));

        return tap(
            BiometricTemplate::query()->updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'device_id' => $device->id,
                    'template_type' => (string) $template['template_type'],
                    'template_index' => $templateIndex,
                ],
                [
                    'finger_index' => $template['finger_index'],
                    'data_size' => (int) ($template['data_size'] ?? strlen($plainTemplate)),
                    'template_hash' => sha1($plainTemplate),
                    'template_data' => $plainTemplate,
                    'metadata' => $template['metadata'] ?? [],
                ],
            ),
            function (BiometricTemplate $record) use ($employee, $device): void {
                $record->setRelation('employee', $employee);
                $record->setRelation('device', $device);
            }
        );
    }

    private function pushTemplateViaUdp(object $client, Device $targetDevice, Employee $employee, BiometricTemplate $template, bool $overwrite): array
    {
        $pin = $this->resolveNumericPin($employee);
        if ($pin === null) {
            throw new \RuntimeException("Employee {$employee->employee_id} has no numeric device PIN.");
        }

        try {
            $this->connectUdpClient($client);
            $this->disableUdpDeviceIfSupported($client);
            $this->ensureTargetUserExists($client, $employee);

            if ($template->template_type === BiometricTemplate::TYPE_FINGERPRINT) {
                if (! method_exists($client, 'setUserTemplate')) {
                    return $this->queueAdmsTemplatePush($targetDevice, $employee, $template, $overwrite);
                }

                $fingerIndex = (int) ($template->finger_index ?? $template->template_index ?? 0);
                $existing = method_exists($client, 'getUserTemplate')
                    ? $client->getUserTemplate((int) $pin, $fingerIndex)
                    : null;

                if ($existing && ! $overwrite) {
                    return ['status' => 'skipped', 'reason' => 'Target fingerprint already exists'];
                }

                $payload = $this->packLegacyFingerprintTemplate(
                    (int) $pin,
                    $fingerIndex,
                    true,
                    (string) $template->template_data,
                );

                $client->setUserTemplate($payload);

                if (method_exists($client, 'getUserTemplate')) {
                    $roundTrip = $client->getUserTemplate((int) $pin, $fingerIndex);
                    $normalized = $this->normalizeUdpFingerprintTemplate($roundTrip, $fingerIndex);

                    if (! $normalized || sha1((string) $normalized['template_data']) !== $template->template_hash) {
                        throw new \RuntimeException('Round-trip fingerprint validation failed after UDP write.');
                    }
                }

                return ['status' => 'udp_pushed'];
            }

            if ($template->template_type === BiometricTemplate::TYPE_FACE) {
                if (! method_exists($client, 'setFaceData')) {
                    throw new \RuntimeException('The installed UDP SDK does not support face writes.');
                }

                $templateIndex = (int) ($template->template_index ?: 50);
                $existing = method_exists($client, 'getFaceData') ? (array) $client->getFaceData((int) $pin) : [];

                if (isset($existing[$templateIndex]) && ! $overwrite) {
                    return ['status' => 'skipped', 'reason' => 'Target face template already exists'];
                }

                $client->setFaceData((int) $pin, [
                    $templateIndex => [
                        'template' => (string) $template->template_data,
                    ],
                ]);

                if (method_exists($client, 'getFaceData')) {
                    $roundTrip = (array) $client->getFaceData((int) $pin);
                    $readBackTemplate = (string) (($roundTrip[$templateIndex]['template'] ?? $roundTrip[$templateIndex] ?? ''));

                    if ($readBackTemplate === '' || sha1($readBackTemplate) !== $template->template_hash) {
                        throw new \RuntimeException('Round-trip face validation failed after UDP write.');
                    }
                }

                return ['status' => 'udp_pushed'];
            }

            throw new \RuntimeException("Unsupported biometric template type {$template->template_type}.");
        } finally {
            $this->enableUdpDeviceIfSupported($client);
            $this->disconnectUdpClientIfSupported($client);
        }
    }

    private function queueAdmsTemplatePush(Device $targetDevice, Employee $employee, BiometricTemplate $template, bool $overwrite): array
    {
        $pin = $this->resolveNumericPin($employee);
        if ($pin === null) {
            throw new \RuntimeException("Employee {$employee->employee_id} has no numeric device PIN.");
        }

        $this->commandService->queueCommand(
            $targetDevice->id,
            'SYNC_EMPLOYEES',
            array_filter([
                'employee_code' => $employee->employee_id,
                'pin' => $pin,
                'name' => $employee->sync_name ?: $employee->name ?: $employee->full_name,
                'card_no' => $employee->card_no,
                'password' => $employee->device_password,
                'privilege' => $employee->device_privilege,
                'group_code' => $employee->group_code,
                'timezone_code' => $employee->timezone_code,
                'verification_mode' => $employee->verification_mode,
                'vice_card_no' => $employee->vice_card_no,
                'valid_from' => $employee->valid_from,
                'valid_until' => $employee->valid_until,
            ], static fn ($value) => $value !== null && $value !== ''),
            "Biometric sync precondition for employee {$employee->employee_id}",
            [
                'employee_id' => $employee->id,
                'force' => $overwrite,
            ]
        );

        $this->commandService->queueCommand(
            $targetDevice->id,
            'SYNC_BIOMETRICS',
            array_filter([
                'employee_code' => $employee->employee_id,
                'pin' => $pin,
                'biometric_type' => $template->template_type,
                'template_index' => $template->template_index,
                'data_size' => $template->data_size ?: strlen((string) $template->template_data),
                'is_valid' => true,
                'template_data' => $template->template_data,
            ], static fn ($value) => $value !== null && $value !== ''),
            "ADMS biometric sync for employee {$employee->employee_id}",
            [
                'employee_id' => $employee->id,
                'force' => $overwrite,
            ]
        );

        return ['status' => 'queued_adms'];
    }

    private function normalizeUdpFingerprintTemplate(mixed $rawTemplate, int $fingerIndex): ?array
    {
        if ($rawTemplate === null || $rawTemplate === false || $rawTemplate === '' || $rawTemplate === []) {
            return null;
        }

        if (is_array($rawTemplate)) {
            $plainTemplate = (string) ($rawTemplate['template'] ?? $rawTemplate[4] ?? '');
            if ($plainTemplate === '') {
                return null;
            }

            $normalizedIndex = (string) ($rawTemplate['finger'] ?? $rawTemplate['finger_id'] ?? $rawTemplate[2] ?? $fingerIndex);

            return [
                'template_type' => BiometricTemplate::TYPE_FINGERPRINT,
                'finger_index' => (int) $normalizedIndex,
                'template_index' => $normalizedIndex,
                'template_data' => $plainTemplate,
                'data_size' => (int) ($rawTemplate['size'] ?? $rawTemplate[0] ?? strlen($plainTemplate)),
                'metadata' => [
                    'source' => 'udp',
                    'command_name' => 'CMD_USERTEMP_RRQ',
                    'command_id' => self::LEGACY_CMD_USERTEMP_RRQ,
                ],
            ];
        }

        if (is_string($rawTemplate) && strlen($rawTemplate) >= 6) {
            $header = unpack('vsize/vpin/Cfinger_id/Cvalid', substr($rawTemplate, 0, 6));
            $plainTemplate = substr($rawTemplate, 6);

            if (! is_array($header) || $plainTemplate === '') {
                return null;
            }

            return [
                'template_type' => BiometricTemplate::TYPE_FINGERPRINT,
                'finger_index' => (int) ($header['finger_id'] ?? $fingerIndex),
                'template_index' => (string) ($header['finger_id'] ?? $fingerIndex),
                'template_data' => $plainTemplate,
                'data_size' => (int) ($header['size'] ?? strlen($plainTemplate)),
                'metadata' => [
                    'source' => 'udp',
                    'command_name' => 'CMD_USERTEMP_RRQ',
                    'command_id' => self::LEGACY_CMD_USERTEMP_RRQ,
                    'legacy_packet' => [
                        'header' => "pack('vvvv', ".self::LEGACY_CMD_USERTEMP_RRQ.", \$checksum, \$sessionId, \$replyId)",
                        'payload' => "pack('vv', \$uid, {$fingerIndex})",
                    ],
                ],
            ];
        }

        return null;
    }

    private function packLegacyFingerprintTemplate(int $uid, int $fingerIndex, bool $valid, string $templateBytes): string
    {
        return pack('vvCC', strlen($templateBytes), $uid, $fingerIndex, $valid ? 1 : 0).$templateBytes;
    }

    private function resolveSourceDevice(Employee $employee): ?Device
    {
        $latestTemplateDeviceId = BiometricTemplate::query()
            ->where('employee_id', $employee->id)
            ->latest('updated_at')
            ->value('device_id');

        if ($latestTemplateDeviceId) {
            return Device::query()->find($latestTemplateDeviceId);
        }

        $latestAdmsBiometricDeviceId = EmployeeBiometric::query()
            ->where('employee_id', $employee->id)
            ->latest('updated_at')
            ->value('device_id');

        if ($latestAdmsBiometricDeviceId) {
            return Device::query()->find($latestAdmsBiometricDeviceId);
        }

        return $employee->device;
    }

    private function resolveDeviceByIp(string $ipAddress): Device
    {
        $device = Device::query()->where('ip_address', trim($ipAddress))->first();

        if (! $device) {
            throw new \InvalidArgumentException("No device is registered for IP {$ipAddress}.");
        }

        return $device;
    }

    private function ensureTargetUserExists(object $client, Employee $employee): void
    {
        if (! method_exists($client, 'setUser')) {
            return;
        }

        $pin = $this->resolveNumericPin($employee);
        if ($pin === null) {
            return;
        }

        $client->setUser(
            (int) $pin,
            $employee->employee_id,
            $employee->sync_name ?: $employee->name ?: $employee->full_name,
            $employee->device_password ?? '0',
            (int) ($employee->device_privilege ?? 0),
            (int) ($employee->card_no ?: 0),
        );
    }

    private function resolveNumericPin(Employee $employee): ?string
    {
        foreach ([$employee->sync_uid, $employee->uid, $employee->employee_id] as $candidate) {
            $candidate = preg_replace('/[^0-9]/', '', trim((string) $candidate));

            if ($candidate !== '') {
                return substr($candidate, 0, 32);
            }
        }

        return null;
    }

    private function makeUdpClient(Device $device): ?object
    {
        $ip = trim((string) $device->ip_address);
        if ($ip === '') {
            return null;
        }

        $port = (int) ($device->port ?: 4370);
        $candidates = [
            'Jmrashed\\Zkteco\\Lib\\ZKTeco',
            'Fsuuaas\\Zkteco\\Zkteco',
            'ZKLibrary',
        ];

        foreach ($candidates as $class) {
            if (! class_exists($class)) {
                continue;
            }

            return $class === 'ZKLibrary'
                ? new $class($ip, $port, 'UDP')
                : new $class($ip, $port);
        }

        return null;
    }

    private function connectUdpClient(object $client): void
    {
        if (! method_exists($client, 'connect')) {
            throw new \RuntimeException('The detected UDP SDK does not expose connect().');
        }

        $result = $client->connect();
        if ($result === false) {
            throw new \RuntimeException('UDP SDK connect() returned false.');
        }
    }

    private function disconnectUdpClientIfSupported(object $client): void
    {
        if (method_exists($client, 'disconnect')) {
            try {
                $client->disconnect();
            } catch (\Throwable) {
                // Best-effort cleanup only.
            }
        }
    }

    private function disableUdpDeviceIfSupported(object $client): void
    {
        if (method_exists($client, 'disableDevice')) {
            try {
                $client->disableDevice();
            } catch (\Throwable) {
                // Best-effort cleanup only.
            }
        }
    }

    private function enableUdpDeviceIfSupported(object $client): void
    {
        if (method_exists($client, 'enableDevice')) {
            try {
                $client->enableDevice();
            } catch (\Throwable) {
                // Best-effort cleanup only.
            }
        }
    }

    private function normalizeModelDescriptor(mixed $model): array
    {
        if ($model instanceof Device) {
            $label = trim((string) ($model->name ?: $model->device_type ?: $model->serial_number));
            $rawConfig = is_array($model->raw_config) ? $model->raw_config : [];
            $normalized = strtolower($label.' '.json_encode($rawConfig));
            $platform = $this->readDeviceHint($model, ['Platform', 'PlatformInfo', 'platform', 'platform_info']);
            $fingerAlgorithm = $this->readDeviceHint($model, ['FingerAlgVersion', 'FingerVersion', 'ZKFPVersion']);
            $faceAlgorithm = $this->readDeviceHint($model, ['FaceAlgVersion', 'FaceVersion', 'ZKFaceVersion']);
        } else {
            $label = trim((string) $model);
            $normalized = strtolower($label);
            $platform = null;
            $fingerAlgorithm = null;
            $faceAlgorithm = null;
        }

        $faceCapable = str_contains($normalized, 'speedface')
            || str_contains($normalized, 'uface')
            || str_contains($normalized, 'iface')
            || str_contains($normalized, 'face')
            || preg_match('/\bmb\d+/i', $label) === 1
            || preg_match('/\bvf\d+/i', $label) === 1;

        $fingerprintCapable = $faceCapable
            || str_contains($normalized, 'finger')
            || str_contains($normalized, 'k40')
            || str_contains($normalized, 'iclock')
            || str_contains($normalized, 'f18')
            || str_contains($normalized, 'ma300');

        $family = null;
        foreach (['speedface', 'uface', 'iface', 'k40', 'iclock', 'f18', 'ma300'] as $candidate) {
            if (str_contains($normalized, $candidate)) {
                $family = $candidate;
                break;
            }
        }

        return [
            'label' => $label !== '' ? $label : 'Unknown device',
            'family' => $family,
            'platform' => $platform,
            'finger_algorithm' => $fingerAlgorithm,
            'face_algorithm' => $faceAlgorithm,
            'face_capable' => $faceCapable,
            'fingerprint_capable' => $fingerprintCapable,
        ];
    }

    private function readDeviceHint(Device $device, array $keys): ?string
    {
        $rawConfig = is_array($device->raw_config) ? $device->raw_config : [];

        foreach ($keys as $key) {
            $value = trim((string) ($rawConfig[$key] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
