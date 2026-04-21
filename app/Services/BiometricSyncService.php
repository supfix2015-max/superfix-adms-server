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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class BiometricSyncService
{
    public const PUSH_TYPE_ALL = 'all';

    private const LEGACY_CMD_USERTEMP_RRQ = 0x09;

    private const LEGACY_CMD_USERTEMP_WRQ = 0x0A;

    public function __construct(
        protected CommandService $commandService,
    ) {}

    public function queueAdmsReplay(Employee $employee, array $options = []): array
    {
        $options = array_merge([
            'source_device' => null,
            'sync_keys' => [],
            'template_types' => [BiometricTemplate::TYPE_FINGERPRINT, BiometricTemplate::TYPE_FACE],
            'overwrite' => true,
            'online_only' => true,
            'target_device_ids' => [],
        ], $options);

        $employee->loadMissing('device');

        $sourceDevice = $options['source_device'] instanceof Device
            ? $options['source_device']
            : $this->resolveSourceDevice($employee);

        $templateTypes = $this->normalizeTemplateTypes((array) $options['template_types']);
        $templates = $this->loadAdmsReplayTemplates(
            $employee,
            $sourceDevice,
            $templateTypes,
            (array) $options['sync_keys'],
        );

        if ($templates->isEmpty()) {
            throw new \RuntimeException(
                "No matching biometric templates are stored locally for employee {$employee->employee_id}."
            );
        }

        $targets = $this->resolveAdmsReplayTargets(
            $sourceDevice,
            (bool) $options['online_only'],
            array_map('intval', (array) $options['target_device_ids']),
        );

        if ($targets->isEmpty()) {
            $scopeLabel = $options['online_only'] ? 'online' : 'active';
            throw new \RuntimeException("No {$scopeLabel} target devices are available for biometric replay.");
        }

        $summarySourceDevice = $sourceDevice ?: $templates->first()?->device;

        $summary = [
            'employee_id' => $employee->employee_id,
            'source_device' => $summarySourceDevice?->ip_address,
            'template_count' => $templates->count(),
            'target_device_count' => $targets->count(),
            'queued_targets' => 0,
            'queued_commands' => 0,
            'skipped' => 0,
            'warnings' => [],
            'targets' => [],
        ];

        foreach ($targets as $target) {
            $targetQueued = 0;
            $employeeQueued = false;
            $targetWarnings = [];
            $targetTemplates = [];

            foreach ($templates as $template) {
                $compatibility = $this->validateDeviceCompatibility($template->device ?: $summarySourceDevice ?: $target, $target);
                $targetWarnings = array_values(array_unique(array_merge($targetWarnings, (array) ($compatibility['warnings'] ?? []))));
                $rule = $template->template_type === BiometricTemplate::TYPE_FACE
                    ? $compatibility['face']
                    : $compatibility['fingerprint'];

                if (! ($rule['compatible'] ?? false)) {
                    $summary['skipped']++;
                    $reason = (string) ($rule['reason'] ?? 'Template type is not compatible with the target device.');
                    $this->flagSyncFailure($template, $target, $reason);
                    $targetTemplates[] = [
                        'type' => $template->template_type,
                        'slot' => $template->template_index,
                        'status' => 'skipped',
                        'reason' => $reason,
                    ];
                    continue;
                }

                if (! $employeeQueued) {
                    $this->queueAdmsEmployeePrecondition($target, $employee);
                    $employeeQueued = true;
                    $targetQueued++;
                }

                $this->queueAdmsBiometricReplayCommand($target, $employee, $template, (bool) $options['overwrite']);
                $targetQueued++;
                $targetTemplates[] = [
                    'type' => $template->template_type,
                    'slot' => $template->template_index,
                    'status' => 'queued',
                ];
            }

            if ($targetQueued > 0) {
                $summary['queued_targets']++;
                $summary['queued_commands'] += $targetQueued;
            }

            $summary['targets'][] = [
                'device_id' => $target->id,
                'device_name' => $target->name,
                'queued_commands' => $targetQueued,
                'templates' => $targetTemplates,
                'warnings' => $targetWarnings,
            ];

            foreach ($targetWarnings as $warning) {
                $summary['warnings'][] = "{$target->name}: {$warning}";
            }
        }

        $summary['warnings'] = array_values(array_unique($summary['warnings']));

        Log::info('adms.biometric_replay.queued', [
            'employee_id' => $employee->id,
            'employee_code' => $employee->employee_id,
            'template_count' => $summary['template_count'],
            'target_device_count' => $summary['target_device_count'],
            'queued_commands' => $summary['queued_commands'],
            'online_only' => (bool) $options['online_only'],
            'template_types' => $templateTypes,
        ]);

        return $summary;
    }

    public function syncEmployeeTemplates(Employee $employee, array $options = []): array
    {
        if (! $this->hasEncryptedTemplateStore()) {
            Log::warning('Biometric sync skipped because biometric_templates table is missing.', [
                'employee_id' => $employee->id,
                'employee_code' => $employee->employee_id,
            ]);

            return [
                'employee_id' => $employee->employee_id,
                'source_device' => $employee->device?->ip_address,
                'template_count' => 0,
                'jobs_dispatched' => 0,
                'skipped' => 0,
                'warnings' => [
                    'Automatic biometric sync is disabled until the biometric_templates migration is applied.',
                ],
            ];
        }

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
        $this->queueAdmsEmployeePrecondition($targetDevice, $employee);
        $this->queueAdmsBiometricReplayCommand($targetDevice, $employee, $template, $overwrite);

        return ['status' => 'queued_adms'];
    }

    private function queueAdmsEmployeePrecondition(Device $targetDevice, Employee $employee): void
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
            "ADMS biometric replay precondition for employee {$employee->employee_id}",
            [
                'employee_id' => $employee->id,
                'force' => false,
            ]
        );
    }

    private function queueAdmsBiometricReplayCommand(
        Device $targetDevice,
        Employee $employee,
        BiometricTemplate $template,
        bool $overwrite,
    ): void {
        $pin = $this->resolveNumericPin($employee);
        if ($pin === null) {
            throw new \RuntimeException("Employee {$employee->employee_id} has no numeric device PIN.");
        }

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
                'template_transport' => $this->resolveTemplateTransportFromMetadata($template->metadata),
                'face_upload_prefixed' => $this->hasFaceUploadPrefixInMetadata($template->template_type, $template->metadata),
            ], static fn ($value) => $value !== null && $value !== ''),
            "ADMS biometric replay for employee {$employee->employee_id}",
            [
                'employee_id' => $employee->id,
                'force' => $overwrite,
            ]
        );
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
        if ($this->hasEncryptedTemplateStore()) {
            $latestTemplateDeviceId = BiometricTemplate::query()
                ->where('employee_id', $employee->id)
                ->latest('updated_at')
                ->value('device_id');

            if ($latestTemplateDeviceId) {
                return Device::query()->find($latestTemplateDeviceId);
            }
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

    private function hasEncryptedTemplateStore(): bool
    {
        return Schema::hasTable('biometric_templates');
    }

    private function normalizeTemplateTypes(array $templateTypes): array
    {
        $normalized = collect($templateTypes)
            ->map(static fn (mixed $type): string => strtolower(trim((string) $type)))
            ->filter(static fn (string $type): bool => in_array($type, [
                BiometricTemplate::TYPE_FINGERPRINT,
                BiometricTemplate::TYPE_FACE,
                self::PUSH_TYPE_ALL,
            ], true))
            ->values();

        if ($normalized->contains(self::PUSH_TYPE_ALL) || $normalized->isEmpty()) {
            return [BiometricTemplate::TYPE_FINGERPRINT, BiometricTemplate::TYPE_FACE];
        }

        return $normalized->unique()->all();
    }

    private function resolveAdmsReplayTargets(?Device $sourceDevice, bool $onlineOnly, array $targetDeviceIds): Collection
    {
        return Device::query()
            ->when($onlineOnly, fn ($query) => $query->online(), fn ($query) => $query->active())
            ->when($targetDeviceIds !== [], fn ($query) => $query->whereIn('id', $targetDeviceIds))
            ->when($sourceDevice, fn ($query) => $query->whereKeyNot($sourceDevice->id))
            ->orderBy('name')
            ->get();
    }

    private function loadAdmsReplayTemplates(
        Employee $employee,
        ?Device $sourceDevice,
        array $templateTypes,
        array $syncKeys = [],
    ): Collection {
        $query = EmployeeBiometric::query()
            ->with('device')
            ->where('employee_id', $employee->id)
            ->whereIn('biometric_type', $templateTypes)
            ->when($sourceDevice, fn ($builder) => $builder->where('device_id', $sourceDevice->id))
            ->when($syncKeys !== [], fn ($builder) => $builder->whereIn('sync_key', $syncKeys))
            ->orderByDesc('updated_at');

        $rows = $query->get();

        if ($rows->isEmpty() && $sourceDevice) {
            $rows = EmployeeBiometric::query()
                ->with('device')
                ->where('employee_id', $employee->id)
                ->whereIn('biometric_type', $templateTypes)
                ->when($syncKeys !== [], fn ($builder) => $builder->whereIn('sync_key', $syncKeys))
                ->orderByDesc('updated_at')
                ->get();
        }

        return $rows
            ->filter(static fn (EmployeeBiometric $biometric): bool => trim((string) $biometric->template_data) !== '')
            ->map(function (EmployeeBiometric $biometric) use ($employee, $sourceDevice): BiometricTemplate {
                $templateType = strtolower((string) $biometric->biometric_type);
                $templateIndex = $biometric->template_index ?: ($templateType === BiometricTemplate::TYPE_FACE ? '50' : '0');

                $template = new BiometricTemplate([
                    'employee_id' => $employee->id,
                    'device_id' => $biometric->device_id ?: $sourceDevice?->id,
                    'template_type' => $templateType,
                    'template_index' => (string) $templateIndex,
                    'finger_index' => $templateType === BiometricTemplate::TYPE_FINGERPRINT
                        ? (int) ($biometric->template_index ?? 0)
                        : null,
                    'data_size' => $biometric->data_size ?: strlen((string) $biometric->template_data),
                    'template_hash' => sha1((string) $biometric->template_data),
                    'template_data' => (string) $biometric->template_data,
                    'metadata' => is_array($biometric->raw_payload) ? $biometric->raw_payload : [],
                ]);

                $template->setRelation('employee', $employee);
                $template->setRelation('device', $biometric->device ?: $sourceDevice);

                return $template;
            })
            ->unique(static fn (BiometricTemplate $template): string => sha1(json_encode([
                'type' => $template->template_type,
                'index' => $template->template_index,
                'hash' => $template->template_hash,
            ], JSON_THROW_ON_ERROR)))
            ->values();
    }

    private function resolveTemplateTransportFromMetadata(?array $metadata): ?string
    {
        $metadata = is_array($metadata) ? $metadata : [];

        if (($metadata['source'] ?? null) === 'udp') {
            return 'udp';
        }

        if (! empty($metadata['template_transport'])) {
            return (string) $metadata['template_transport'];
        }

        if (array_key_exists('TMP', $metadata) || array_key_exists('Content', $metadata)) {
            return 'adms_upload';
        }

        return null;
    }

    private function hasFaceUploadPrefixInMetadata(string $templateType, ?array $metadata): bool
    {
        if ($templateType !== BiometricTemplate::TYPE_FACE) {
            return false;
        }

        $metadata = is_array($metadata) ? $metadata : [];

        if (array_key_exists('face_upload_prefixed', $metadata)) {
            return (bool) $metadata['face_upload_prefixed'];
        }

        if (($metadata['source'] ?? null) === 'udp') {
            return false;
        }

        return false;
    }
}
