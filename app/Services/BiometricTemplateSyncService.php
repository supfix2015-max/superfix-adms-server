<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeBiometric;
use App\Models\ErrorLog;
use App\Notifications\BiometricReenrollmentRequiredNotification;
use Illuminate\Support\Collection;

/**
 * Synchronize fingerprint and face templates between devices.
 *
 * Current-project reality:
 * - This Laravel app talks to devices primarily through the ADMS push protocol.
 * - Employee biometrics already stored in the local database are the most reliable
 *   source of truth for cross-device biometric replay in this codebase.
 * - Direct UDP extraction / upload is optional here. It is attempted only when a
 *   compatible PHP SDK is installed and exposes the required methods.
 *
 * Legacy UDP reference for classic TFT devices:
 * - CMD_USERTEMP_RRQ = 0x09
 * - CMD_USERTEMP_WRQ = 0x0A
 * - packet header    = pack('vvvv', $command, $checksum, $sessionId, $replyId)
 * - fingerprint read payload  is typically pack('vv', $uid, $fingerIndex)
 * - fingerprint write payload is typically pack('vvCC', $size, $uid, $fingerIndex, $valid).$templateBytes
 *
 * Notes:
 * - CMD_USERTEMP_* is a legacy fingerprint path. It is not a reliable universal
 *   transport for face templates across modern device families.
 * - Face templates are often model / firmware / algorithm specific; for those,
 *   this service treats direct cross-family transfer as unsafe and falls back.
 */
class BiometricTemplateSyncService
{
    private const LEGACY_CMD_USERTEMP_RRQ = 0x09;

    private const LEGACY_CMD_USERTEMP_WRQ = 0x0A;

    private const SUPPORTED_BIOMETRIC_TYPES = [
        EmployeeBiometric::TYPE_FINGERPRINT,
        EmployeeBiometric::TYPE_FACE,
    ];

    public function __construct(
        protected CommandService $commandService,
    ) {}

    public function fetchTemplatesFromDevice(string $deviceIp, string $userId): array
    {
        $source = $this->resolveRegisteredDeviceByIp($deviceIp);
        $employee = $this->resolveEmployeeForDevice($source, $userId);
        $employees = collect([$employee]);

        $udpSummary = $this->downloadSourceTemplatesViaUdp($source, $employees);
        $templates = $this->loadSourceTemplatesFromDatabase($source, $employees);

        if ($templates->isEmpty()) {
            throw new \RuntimeException(
                "No fingerprint or face templates are available for employee {$employee->employee_id} on device {$source->name}. "
                .'The installed UDP SDK either could not fetch them or no locally stored ADMS copy exists.'
            );
        }

        return [
            'framework' => app()->version(),
            'integration_method' => 'ADMS push protocol (/iclock/cdata, /iclock/getrequest, /iclock/devicecmd)',
            'source' => $this->serializeDeviceMetadata($source),
            'employee' => $this->serializeEmployeeMetadata($employee),
            'udp' => $udpSummary,
            'protocol' => [
                'fingerprint_read_command' => 'CMD_USERTEMP_RRQ',
                'fingerprint_read_command_id' => self::LEGACY_CMD_USERTEMP_RRQ,
                'fingerprint_write_command' => 'CMD_USERTEMP_WRQ',
                'fingerprint_write_command_id' => self::LEGACY_CMD_USERTEMP_WRQ,
                'udp_header' => "pack('vvvv', \$command, \$checksum, \$sessionId, \$replyId)",
                'read_payload' => "pack('vv', \$uid, \$fingerIndex)",
                'write_payload' => "pack('vvCC', strlen(\$templateBytes), \$uid, \$fingerIndex, \$valid ? 1 : 0) . \$templateBytes",
            ],
            'templates' => $templates
                ->map(fn (EmployeeBiometric $template): array => $this->serializeTemplate($template))
                ->values()
                ->all(),
        ];
    }

    public function pushTemplatesToDevice(string $deviceIp, string $userId, array $templates, array $options = []): array
    {
        $options = array_merge([
            'overwrite' => false,
            'prefer_udp' => true,
            'fallback' => 'notify',
        ], $options);

        $target = $this->resolveRegisteredDeviceByIp($deviceIp);
        $normalized = $this->normalizeFetchedTemplatePayload($userId, $templates);

        return $this->syncTemplatesToTarget(
            $normalized['source'],
            $target,
            $normalized['templates'],
            [
                'force' => (bool) $options['overwrite'],
                'prefer_udp' => (bool) $options['prefer_udp'],
                'fallback' => (string) $options['fallback'],
            ],
        );
    }

    public function validateDeviceCompatibility(mixed $sourceModel, mixed $targetModel): array
    {
        if ($sourceModel instanceof Device && $targetModel instanceof Device) {
            $fingerprint = $this->assessCompatibility(
                $sourceModel,
                $targetModel,
                new EmployeeBiometric(['biometric_type' => EmployeeBiometric::TYPE_FINGERPRINT]),
            );

            $face = $this->assessCompatibility(
                $sourceModel,
                $targetModel,
                new EmployeeBiometric(['biometric_type' => EmployeeBiometric::TYPE_FACE]),
            );

            return [
                'source_model' => $sourceModel->name ?: $sourceModel->device_type ?: $sourceModel->serial_number,
                'target_model' => $targetModel->name ?: $targetModel->device_type ?: $targetModel->serial_number,
                'fingerprint' => $fingerprint,
                'face' => $face,
                'compatible' => $fingerprint['compatible'] || $face['compatible'],
                'warnings' => array_values(array_unique(array_merge($fingerprint['warnings'], $face['warnings']))),
            ];
        }

        $sourceDescriptor = $this->normalizeModelDescriptor($sourceModel);
        $targetDescriptor = $this->normalizeModelDescriptor($targetModel);
        $warnings = [];

        $fingerprintCompatible = $sourceDescriptor['fingerprint_capable'] && $targetDescriptor['fingerprint_capable'];
        $faceCompatible = $sourceDescriptor['face_capable'] && $targetDescriptor['face_capable'];

        if ($sourceDescriptor['face_capable'] && ! $targetDescriptor['face_capable']) {
            $warnings[] = "Face templates from {$sourceDescriptor['label']} cannot be transferred to fingerprint-only {$targetDescriptor['label']} terminals.";
        }

        if (
            $sourceDescriptor['family'] !== null
            && $targetDescriptor['family'] !== null
            && $sourceDescriptor['family'] !== $targetDescriptor['family']
        ) {
            $warnings[] = "Device families differ ({$sourceDescriptor['family']} -> {$targetDescriptor['family']}); fingerprint replay may work, but physical verification is required.";
        }

        return [
            'source_model' => $sourceDescriptor['label'],
            'target_model' => $targetDescriptor['label'],
            'fingerprint' => [
                'compatible' => $fingerprintCompatible,
                'reason' => $fingerprintCompatible ? null : 'One of the devices is not fingerprint capable.',
                'warnings' => $warnings,
            ],
            'face' => [
                'compatible' => $faceCompatible,
                'reason' => $faceCompatible ? null : 'Face transfer requires both devices to expose a compatible face engine.',
                'warnings' => $warnings,
            ],
            'compatible' => $fingerprintCompatible || $faceCompatible,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    public function syncUserAcrossDevices(string $userId, string $sourceIp, array $targetIps, array $options = []): array
    {
        $fetched = $this->fetchTemplatesFromDevice($sourceIp, $userId);
        $targetSummaries = [];

        foreach ($targetIps as $targetIp) {
            $targetSummaries[] = $this->pushTemplatesToDevice((string) $targetIp, $userId, $fetched, $options);
        }

        return [
            'framework' => app()->version(),
            'integration_method' => $fetched['integration_method'],
            'source' => $fetched['source'],
            'employee' => $fetched['employee'],
            'udp' => $fetched['udp'],
            'targets' => $targetSummaries,
            'template_count' => count($fetched['templates']),
        ];
    }

    public function sync(string $sourceIp, array $targetIps, array $options = []): array
    {
        $options = array_merge([
            'employee_codes' => [],
            'prefer_udp' => false,
            'force' => false,
            'fallback' => 'log',
        ], $options);

        $source = $this->resolveRegisteredDeviceByIp($sourceIp);
        $targets = $this->resolveRegisteredTargetDevices($targetIps, $source);
        $employees = $this->resolveSourceEmployees($source, $options['employee_codes']);

        if ($employees->isEmpty()) {
            throw new \RuntimeException("No source employees were found on device {$source->name} ({$source->ip_address}).");
        }

        $udpSummary = [
            'attempted' => false,
            'available' => false,
            'downloaded' => 0,
            'warnings' => [],
        ];

        if ($options['prefer_udp']) {
            $udpSummary = $this->downloadSourceTemplatesViaUdp($source, $employees);
        }

        $templates = $this->loadSourceTemplatesFromDatabase($source, $employees);

        if ($templates->isEmpty()) {
            throw new \RuntimeException(
                'No fingerprint or face templates are available for the selected source device. '
                .'Direct UDP extraction was either unavailable or did not produce usable templates.'
            );
        }

        $targetSummaries = [];
        foreach ($targets as $target) {
            $targetSummaries[] = $this->syncTemplatesToTarget($source, $target, $templates, $options);
        }

        return [
            'framework' => app()->version(),
            'integration_method' => 'ADMS push protocol (/iclock/cdata, /iclock/getrequest, /iclock/devicecmd)',
            'source' => [
                'id' => $source->id,
                'name' => $source->name,
                'serial_number' => $source->serial_number,
                'ip_address' => $source->ip_address,
                'device_type' => $source->device_type,
                'firmware_version' => $source->firmware_version,
            ],
            'udp' => $udpSummary,
            'targets' => $targetSummaries,
            'template_count' => $templates->count(),
            'employee_count' => $templates->pluck('employee_code')->unique()->count(),
        ];
    }

    private function resolveEmployeeForDevice(Device $device, string $userId): Employee
    {
        $employee = Employee::query()
            ->where('device_id', $device->id)
            ->where(function ($query) use ($userId): void {
                $query->where('employee_id', $userId)
                    ->orWhere('sync_uid', $userId)
                    ->orWhere('uid', $userId);

                if (ctype_digit($userId)) {
                    $query->orWhere((new Employee)->getKeyName(), (int) $userId);
                }
            })
            ->first();

        if ($employee) {
            return $employee;
        }

        throw new \InvalidArgumentException(
            "Employee {$userId} was not found on source device {$device->name} ({$device->ip_address})."
        );
    }

    private function resolveEmployeeByIdentifier(string $userId): Employee
    {
        $employee = Employee::query()
            ->where(function ($query) use ($userId): void {
                $query->where('employee_id', $userId)
                    ->orWhere('sync_uid', $userId)
                    ->orWhere('uid', $userId);

                if (ctype_digit($userId)) {
                    $query->orWhere((new Employee)->getKeyName(), (int) $userId);
                }
            })
            ->first();

        if ($employee) {
            return $employee;
        }

        throw new \InvalidArgumentException("Employee {$userId} is not registered in this Laravel app.");
    }

    private function normalizeFetchedTemplatePayload(string $userId, array $payload): array
    {
        $employee = $this->resolveEmployeeByIdentifier($userId);
        $templateRows = isset($payload['templates']) && is_array($payload['templates'])
            ? $payload['templates']
            : $payload;

        if ($templateRows === []) {
            throw new \InvalidArgumentException('No template payload was provided.');
        }

        $source = null;
        if (isset($payload['source']['ip_address'])) {
            $source = $this->resolveRegisteredDeviceByIp((string) $payload['source']['ip_address']);
        }

        if (! $source) {
            $sourceDeviceId = collect($templateRows)
                ->map(static fn (mixed $row): ?int => is_array($row) && isset($row['device_id']) ? (int) $row['device_id'] : null)
                ->filter()
                ->first();

            if ($sourceDeviceId) {
                $source = Device::query()->find($sourceDeviceId);
            }
        }

        $source ??= $employee->device;

        if (! $source) {
            throw new \InvalidArgumentException('The source device could not be derived from the provided template payload.');
        }

        $templates = collect($templateRows)
            ->map(function (mixed $row) use ($employee, $source): ?EmployeeBiometric {
                if (! is_array($row)) {
                    return null;
                }

                $templateData = (string) ($row['template_data'] ?? '');
                if ($templateData === '') {
                    return null;
                }

                $template = new EmployeeBiometric([
                    'device_id' => (int) ($row['device_id'] ?? $source->id),
                    'employee_id' => $employee->id,
                    'employee_code' => (string) ($row['employee_code'] ?? $employee->employee_id),
                    'biometric_type' => (string) ($row['biometric_type'] ?? ''),
                    'template_index' => isset($row['template_index']) ? (string) $row['template_index'] : null,
                    'file_name' => isset($row['file_name']) ? (string) $row['file_name'] : null,
                    'is_valid' => array_key_exists('is_valid', $row) ? (bool) $row['is_valid'] : true,
                    'data_size' => isset($row['data_size']) ? (int) $row['data_size'] : strlen($templateData),
                    'template_data' => $templateData,
                    'raw_payload' => is_array($row['raw_payload'] ?? null) ? $row['raw_payload'] : [],
                ]);

                $template->setRelation('employee', $employee);

                return $template;
            })
            ->filter(fn (?EmployeeBiometric $template): bool => $template instanceof EmployeeBiometric)
            ->values();

        if ($templates->isEmpty()) {
            throw new \InvalidArgumentException('The provided template payload does not contain any usable biometric templates.');
        }

        return [
            'source' => $source,
            'employee' => $employee,
            'templates' => $templates,
        ];
    }

    private function serializeDeviceMetadata(Device $device): array
    {
        return [
            'id' => $device->id,
            'name' => $device->name,
            'serial_number' => $device->serial_number,
            'ip_address' => $device->ip_address,
            'device_type' => $device->device_type,
            'firmware_version' => $device->firmware_version,
        ];
    }

    private function serializeEmployeeMetadata(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'sync_uid' => $employee->sync_uid,
            'uid' => $employee->uid,
            'name' => $employee->sync_name ?: $employee->name ?: $employee->full_name,
            'email' => $employee->email,
        ];
    }

    private function serializeTemplate(EmployeeBiometric $template): array
    {
        return [
            'device_id' => $template->device_id,
            'employee_id' => $template->employee_id,
            'employee_code' => $template->employee_code,
            'biometric_type' => $template->biometric_type,
            'template_index' => $template->template_index,
            'file_name' => $template->file_name,
            'is_valid' => $template->is_valid,
            'data_size' => $template->data_size ?: strlen((string) $template->template_data),
            'template_data' => $template->template_data,
            'raw_payload' => is_array($template->raw_payload) ? $template->raw_payload : [],
        ];
    }

    private function normalizeModelDescriptor(mixed $model): array
    {
        if ($model instanceof Device) {
            $label = trim((string) ($model->name ?: $model->device_type ?: $model->serial_number));
            $normalized = strtolower($label.' '.json_encode($model->raw_config ?? []));
        } else {
            $label = trim((string) $model);
            $normalized = strtolower($label);
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
            'face_capable' => $faceCapable,
            'fingerprint_capable' => $fingerprintCapable,
        ];
    }

    private function resolveRegisteredDeviceByIp(string $ipAddress): Device
    {
        $device = Device::query()->where('ip_address', $ipAddress)->first();

        if (! $device) {
            throw new \InvalidArgumentException(
                "No registered device was found for IP {$ipAddress}. "
                .'This project requires registered devices because compatibility checks and fallback logging depend on local device records.'
            );
        }

        return $device;
    }

    private function resolveRegisteredTargetDevices(array $targetIps, Device $source): Collection
    {
        $normalizedIps = collect($targetIps)
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        if ($normalizedIps->isEmpty()) {
            throw new \InvalidArgumentException('At least one target device IP is required.');
        }

        $targets = Device::query()
            ->whereIn('ip_address', $normalizedIps->all())
            ->whereKeyNot($source->id)
            ->get()
            ->keyBy('ip_address');

        $missingIps = $normalizedIps->reject(fn (string $ip): bool => $targets->has($ip));
        if ($missingIps->isNotEmpty()) {
            throw new \InvalidArgumentException(
                'The following target device IPs are not registered in this Laravel app: '.$missingIps->implode(', ')
            );
        }

        return $targets->values();
    }

    private function resolveSourceEmployees(Device $source, array $employeeCodes): Collection
    {
        $employeeCodes = collect($employeeCodes)
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        return Employee::query()
            ->where('device_id', $source->id)
            ->when($employeeCodes->isNotEmpty(), function ($query) use ($employeeCodes): void {
                $query->whereIn('employee_id', $employeeCodes->all());
            })
            ->orderBy('employee_id')
            ->get();
    }

    private function downloadSourceTemplatesViaUdp(Device $source, Collection $employees): array
    {
        $summary = [
            'attempted' => true,
            'available' => false,
            'downloaded' => 0,
            'warnings' => [],
        ];

        $client = $this->makeUdpClient($source);
        if (! $client) {
            $summary['warnings'][] = 'No compatible UDP SDK is installed. Falling back to locally stored ADMS templates.';

            return $summary;
        }

        $summary['available'] = true;

        try {
            try {
                $this->connectUdpClient($client);
                $this->disableUdpDeviceIfSupported($client);

                $rows = [];
                foreach ($employees as $employee) {
                    $rows = array_merge($rows, $this->downloadUdpTemplatesForEmployee($client, $source, $employee, $summary['warnings']));
                }

                if ($rows !== []) {
                    $this->upsertTemplateRows($source, $rows);
                    $summary['downloaded'] = count($rows);
                }
            } catch (\Throwable $exception) {
                $summary['warnings'][] = "UDP source connection failed for {$source->name}: {$exception->getMessage()}";
            }
        } finally {
            $this->enableUdpDeviceIfSupported($client);
            $this->disconnectUdpClientIfSupported($client);
        }

        return $summary;
    }

    private function downloadUdpTemplatesForEmployee(object $client, Device $source, Employee $employee, array &$warnings): array
    {
        $rows = [];
        $pin = $this->resolveNumericPin($employee);

        if ($pin === null) {
            $warnings[] = "Skipped UDP download for employee {$employee->employee_id}: no numeric PIN/UID is available.";

            return [];
        }

        if (method_exists($client, 'getUserTemplate')) {
            for ($fingerIndex = 0; $fingerIndex <= 9; $fingerIndex++) {
                try {
                    $rawTemplate = $client->getUserTemplate((int) $pin, $fingerIndex);
                } catch (\Throwable $exception) {
                    $warnings[] = "UDP fingerprint download failed for employee {$employee->employee_id} finger {$fingerIndex}: {$exception->getMessage()}";
                    continue;
                }

                $normalized = $this->normalizeUdpFingerprintTemplate($source, $employee, $fingerIndex, $rawTemplate);
                if ($normalized !== null) {
                    $rows[] = $normalized;
                }
            }
        } else {
            $warnings[] = 'The installed UDP SDK does not expose getUserTemplate(); fingerprint extraction is unavailable.';
        }

        if (method_exists($client, 'getFaceData')) {
            try {
                $faceData = $client->getFaceData((int) $pin);
            } catch (\Throwable $exception) {
                $warnings[] = "UDP face download failed for employee {$employee->employee_id}: {$exception->getMessage()}";
                $faceData = [];
            }

            foreach ((array) $faceData as $templateIndex => $payload) {
                $templateData = is_array($payload) ? (string) ($payload['template'] ?? '') : (string) $payload;
                if ($templateData === '') {
                    continue;
                }

                $rows[] = $this->makeTemplateRow(
                    $source,
                    $employee,
                    EmployeeBiometric::TYPE_FACE,
                    (string) $templateIndex,
                    null,
                    true,
                    is_array($payload) ? (int) ($payload['size'] ?? strlen($templateData)) : strlen($templateData),
                    $templateData,
                    [
                        'source' => 'udp',
                        'driver' => get_debug_type($client),
                        'command' => 'getFaceData',
                    ],
                );
            }
        } else {
            $warnings[] = 'The installed UDP SDK does not expose getFaceData(); direct face extraction is unavailable.';
        }

        return $rows;
    }

    private function normalizeUdpFingerprintTemplate(Device $source, Employee $employee, int $fingerIndex, mixed $rawTemplate): ?array
    {
        if ($rawTemplate === null || $rawTemplate === false || $rawTemplate === '' || $rawTemplate === []) {
            return null;
        }

        $parsed = null;

        if (is_array($rawTemplate)) {
            $templateData = (string) ($rawTemplate['template'] ?? $rawTemplate[4] ?? '');
            if ($templateData === '') {
                return null;
            }

            $parsed = [
                'size' => (int) ($rawTemplate['size'] ?? $rawTemplate[0] ?? strlen($templateData)),
                'pin' => (string) ($rawTemplate['pin'] ?? $rawTemplate[1] ?? $employee->employee_id),
                'finger_id' => (string) ($rawTemplate['finger'] ?? $rawTemplate['finger_id'] ?? $rawTemplate[2] ?? $fingerIndex),
                'valid' => (bool) ($rawTemplate['valid'] ?? $rawTemplate[3] ?? true),
                'template' => $templateData,
            ];
        } elseif (is_string($rawTemplate)) {
            $parsed = $this->unpackLegacyFingerprintTemplate($rawTemplate);
        }

        if (! $parsed || trim((string) $parsed['template']) === '') {
            return null;
        }

        return $this->makeTemplateRow(
            $source,
            $employee,
            EmployeeBiometric::TYPE_FINGERPRINT,
            (string) $parsed['finger_id'],
            null,
            (bool) $parsed['valid'],
            (int) $parsed['size'],
            (string) $parsed['template'],
            [
                'source' => 'udp',
                'command_id' => self::LEGACY_CMD_USERTEMP_RRQ,
                'command_name' => 'CMD_USERTEMP_RRQ',
                'legacy_packet' => [
                    'header' => "pack('vvvv', ".self::LEGACY_CMD_USERTEMP_RRQ.", \$checksum, \$sessionId, \$replyId)",
                    'payload' => "pack('vv', {$parsed['pin']}, {$parsed['finger_id']})",
                ],
            ],
        );
    }

    private function loadSourceTemplatesFromDatabase(Device $source, Collection $employees): Collection
    {
        return EmployeeBiometric::query()
            ->with('employee')
            ->where('device_id', $source->id)
            ->whereIn('employee_id', $employees->pluck('id')->all())
            ->whereIn('biometric_type', self::SUPPORTED_BIOMETRIC_TYPES)
            ->get()
            ->filter(fn (EmployeeBiometric $biometric): bool => trim((string) $biometric->template_data) !== '')
            ->values();
    }

    private function syncTemplatesToTarget(Device $source, Device $target, Collection $templates, array $options): array
    {
        $summary = [
            'target' => [
                'id' => $target->id,
                'name' => $target->name,
                'serial_number' => $target->serial_number,
                'ip_address' => $target->ip_address,
                'device_type' => $target->device_type,
                'firmware_version' => $target->firmware_version,
            ],
            'queued' => 0,
            'udp_uploaded' => 0,
            'skipped' => 0,
            'fallbacks' => 0,
            'errors' => [],
            'warnings' => [],
        ];

        $targetClient = null;
        if ($options['prefer_udp']) {
            $targetClient = $this->makeUdpClient($target);

            if ($targetClient) {
                try {
                    $this->connectUdpClient($targetClient);
                    $this->disableUdpDeviceIfSupported($targetClient);
                } catch (\Throwable $exception) {
                    $summary['warnings'][] = "Direct UDP upload is unavailable for {$target->name}: {$exception->getMessage()}";
                    $targetClient = null;
                }
            }
        }

        $queuedEmployees = [];

        try {
            foreach ($templates as $template) {
                /** @var EmployeeBiometric $template */
                $employee = $template->employee;

                if (! $employee) {
                    $summary['errors'][] = "Template {$template->id} is not linked to a local employee record.";
                    $summary['fallbacks']++;
                    continue;
                }

                $compatibility = $this->assessCompatibility($source, $target, $template);
                if ($compatibility['warnings'] !== []) {
                    $summary['warnings'] = array_values(array_unique(array_merge($summary['warnings'], $compatibility['warnings'])));
                }

                if (! $compatibility['compatible']) {
                    $this->recordReenrollFallback($target, $employee, $template, $compatibility['reason'], $options['fallback']);
                    $summary['fallbacks']++;
                    $summary['errors'][] = $compatibility['reason'];
                    continue;
                }

                $existing = $this->findExistingTemplateOnTarget($target, $employee, $template);
                if ($existing && ! $options['force']) {
                    $summary['skipped']++;
                    continue;
                }

                if ($targetClient && $this->canTransferDirectlyViaUdp($targetClient, $template)) {
                    try {
                        $this->ensureUserExistsOnUdpTarget($targetClient, $employee);
                        $this->uploadTemplateViaUdp($targetClient, $target, $employee, $template, (bool) $options['force']);
                        $summary['udp_uploaded']++;
                        $this->mirrorTemplateToTargetStore($target, $employee, $template, 'verified_direct');
                        continue;
                    } catch (\Throwable $exception) {
                        $summary['warnings'][] = "Direct UDP upload failed for {$employee->employee_id} on {$target->name}: {$exception->getMessage()}";
                    }
                }

                $employeeQueueKey = $target->id.'|'.$employee->id;
                if (! isset($queuedEmployees[$employeeQueueKey])) {
                    $this->queueEmployeeSyncToTarget($target, $employee, (bool) $options['force']);
                    $queuedEmployees[$employeeQueueKey] = true;
                    $summary['queued']++;
                }

                $this->queueBiometricSyncToTarget($target, $employee, $template, (bool) $options['force']);
                $summary['queued']++;
            }
        } finally {
            if ($targetClient) {
                $this->enableUdpDeviceIfSupported($targetClient);
                $this->disconnectUdpClientIfSupported($targetClient);
            }
        }

        return $summary;
    }

    private function assessCompatibility(Device $source, Device $target, EmployeeBiometric $template): array
    {
        $warnings = [];
        $reason = null;

        $biometricType = $template->biometric_type;
        $sourceType = strtolower((string) $source->device_type);
        $targetType = strtolower((string) $target->device_type);

        if ($biometricType === EmployeeBiometric::TYPE_FACE) {
            if ($targetType !== '' && ! in_array($targetType, ['face', 'mixed'], true)) {
                $reason = "Target device {$target->name} is not marked as a face-capable terminal.";
            }
        }

        if ($biometricType === EmployeeBiometric::TYPE_FINGERPRINT) {
            if ($targetType !== '' && ! in_array($targetType, ['fingerprint', 'mixed', 'face'], true)) {
                $reason = "Target device {$target->name} is not marked as fingerprint-capable.";
            }
        }

        $sourcePlatform = $this->readDeviceHint($source, ['Platform', 'PlatformInfo', 'platform', 'platform_info']);
        $targetPlatform = $this->readDeviceHint($target, ['Platform', 'PlatformInfo', 'platform', 'platform_info']);

        if ($sourcePlatform && $targetPlatform && $sourcePlatform !== $targetPlatform) {
            if ($biometricType === EmployeeBiometric::TYPE_FACE) {
                $reason = $reason ?: "Platform mismatch for face template transfer ({$sourcePlatform} -> {$targetPlatform}).";
            } else {
                $warnings[] = "Platform differs between source and target ({$sourcePlatform} -> {$targetPlatform}); fingerprint replay may fail.";
            }
        }

        $sourceAlgorithm = $this->readAlgorithmVersion($source, $biometricType);
        $targetAlgorithm = $this->readAlgorithmVersion($target, $biometricType);

        if ($sourceAlgorithm && $targetAlgorithm && $sourceAlgorithm !== $targetAlgorithm) {
            $reason = $reason ?: sprintf(
                '%s algorithm version mismatch (%s -> %s).',
                ucfirst($biometricType),
                $sourceAlgorithm,
                $targetAlgorithm,
            );
        }

        $sourceFirmwareMajor = $this->majorVersion($source->firmware_version);
        $targetFirmwareMajor = $this->majorVersion($target->firmware_version);

        if ($sourceFirmwareMajor && $targetFirmwareMajor && $sourceFirmwareMajor !== $targetFirmwareMajor) {
            $warnings[] = "Firmware major versions differ ({$sourceFirmwareMajor} -> {$targetFirmwareMajor}); biometric verification should be physically validated.";
        }

        return [
            'compatible' => $reason === null,
            'reason' => $reason,
            'warnings' => $warnings,
        ];
    }

    private function canTransferDirectlyViaUdp(object $client, EmployeeBiometric $template): bool
    {
        return match ($template->biometric_type) {
            EmployeeBiometric::TYPE_FINGERPRINT => method_exists($client, 'setUserTemplate'),
            EmployeeBiometric::TYPE_FACE => method_exists($client, 'setFaceData'),
            default => false,
        };
    }

    private function ensureUserExistsOnUdpTarget(object $client, Employee $employee): void
    {
        if (! method_exists($client, 'setUser')) {
            return;
        }

        $pin = $this->resolveNumericPin($employee);
        if ($pin === null) {
            throw new \RuntimeException("Employee {$employee->employee_id} has no numeric device PIN.");
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

    private function uploadTemplateViaUdp(object $client, Device $target, Employee $employee, EmployeeBiometric $template, bool $force): void
    {
        $pin = $this->resolveNumericPin($employee);
        if ($pin === null) {
            throw new \RuntimeException("Employee {$employee->employee_id} has no numeric device PIN.");
        }

        if ($template->biometric_type === EmployeeBiometric::TYPE_FINGERPRINT) {
            $templatePayload = $this->packLegacyFingerprintTemplate(
                (int) $pin,
                (int) ($template->template_index ?? 0),
                $template->is_valid !== false,
                (string) $template->template_data,
            );

            $existing = method_exists($client, 'getUserTemplate')
                ? $client->getUserTemplate((int) $pin, (int) ($template->template_index ?? 0))
                : null;

            if ($existing && ! $force) {
                throw new \RuntimeException("Target device {$target->name} already has fingerprint slot {$template->template_index} for user {$employee->employee_id}.");
            }

            /*
             * Exact legacy-style call when the installed SDK exposes the classic method:
             * $client->setUserTemplate($templatePayload);
             *
             * The underlying packet maps to:
             * - command id: CMD_USERTEMP_WRQ (0x0A)
             * - payload   : pack('vvCC', $size, $uid, $fingerIndex, $valid).$templateBytes
             */
            $client->setUserTemplate($templatePayload);

            if (method_exists($client, 'getUserTemplate')) {
                $roundTrip = $client->getUserTemplate((int) $pin, (int) ($template->template_index ?? 0));
                $normalized = $this->normalizeUdpFingerprintTemplate($target, $employee, (int) ($template->template_index ?? 0), $roundTrip);

                if (! $normalized || sha1((string) $normalized['template_data']) !== sha1((string) $template->template_data)) {
                    throw new \RuntimeException(
                        'The device acknowledged the fingerprint write, but a round-trip readback did not match. '
                        .'This usually means the target accepted the packet but could not use the template format.'
                    );
                }
            }

            return;
        }

        if ($template->biometric_type === EmployeeBiometric::TYPE_FACE) {
            $templateIndex = (int) ($template->template_index ?: 50);
            $existing = method_exists($client, 'getFaceData') ? (array) $client->getFaceData((int) $pin) : [];

            if (isset($existing[$templateIndex]) && ! $force) {
                throw new \RuntimeException("Target device {$target->name} already has face slot {$templateIndex} for user {$employee->employee_id}.");
            }

            /*
             * Typical modern SDK-style face call:
             * $client->setFaceData((int) $pin, [
             *     $templateIndex => ['template' => $template->template_data],
             * ]);
             *
             * Face transfer commonly fails across device families even when the SDK returns success,
             * so a readback or physical verification is still required.
             */
            $client->setFaceData((int) $pin, [
                $templateIndex => [
                    'template' => (string) $template->template_data,
                ],
            ]);

            if (method_exists($client, 'getFaceData')) {
                $roundTrip = (array) $client->getFaceData((int) $pin);
                $readBackTemplate = (string) (($roundTrip[$templateIndex]['template'] ?? $roundTrip[$templateIndex] ?? ''));

                if ($readBackTemplate === '' || sha1($readBackTemplate) !== sha1((string) $template->template_data)) {
                    throw new \RuntimeException(
                        'The device acknowledged the face template write, but a round-trip readback did not match. '
                        .'Face algorithms are often firmware and platform specific.'
                    );
                }
            }

            return;
        }

        throw new \RuntimeException("Unsupported biometric type {$template->biometric_type}.");
    }

    private function queueEmployeeSyncToTarget(Device $target, Employee $employee, bool $force): void
    {
        $pin = $this->resolveNumericPin($employee);
        if ($pin === null) {
            throw new \RuntimeException("Employee {$employee->employee_id} has no numeric device PIN.");
        }

        $this->commandService->queueCommand(
            $target->id,
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
                'force' => $force,
            ]
        );
    }

    private function queueBiometricSyncToTarget(Device $target, Employee $employee, EmployeeBiometric $template, bool $force): void
    {
        $pin = $this->resolveNumericPin($employee);
        if ($pin === null) {
            throw new \RuntimeException("Employee {$employee->employee_id} has no numeric device PIN.");
        }

        $this->commandService->queueCommand(
            $target->id,
            'SYNC_BIOMETRICS',
            array_filter([
                'employee_code' => $employee->employee_id,
                'pin' => $pin,
                'biometric_type' => $template->biometric_type,
                'template_index' => $template->template_index,
                'file_name' => $template->file_name,
                'data_size' => $template->data_size ?: strlen((string) $template->template_data),
                'is_valid' => $template->is_valid,
                'template_data' => $template->template_data,
                'template_transport' => $this->resolveTemplateTransport($template),
                'face_upload_prefixed' => $this->hasFaceUploadPrefix($template),
            ], static fn ($value) => $value !== null && $value !== ''),
            "Cross-device biometric sync for employee {$employee->employee_id}",
            [
                'employee_id' => $employee->id,
                'force' => $force,
            ]
        );
    }

    private function findExistingTemplateOnTarget(Device $target, Employee $employee, EmployeeBiometric $template): ?EmployeeBiometric
    {
        $candidate = EmployeeBiometric::query()
            ->where('device_id', $target->id)
            ->where('employee_id', $employee->id)
            ->where('biometric_type', $template->biometric_type)
            ->where('template_index', $template->template_index)
            ->where('file_name', $template->file_name)
            ->first();

        if (! $candidate) {
            return null;
        }

        if (! $this->isConfirmedTargetTemplate($candidate)) {
            return null;
        }

        return sha1((string) $candidate->template_data) === sha1((string) $template->template_data)
            ? $candidate
            : null;
    }

    private function isConfirmedTargetTemplate(EmployeeBiometric $template): bool
    {
        $rawPayload = is_array($template->raw_payload) ? $template->raw_payload : [];
        $mirrored = (bool) ($rawPayload['mirrored_from_sync'] ?? false);
        $syncState = (string) ($rawPayload['sync_state'] ?? '');

        if (! $mirrored) {
            return true;
        }

        return in_array($syncState, ['verified_direct', 'ingested_from_device'], true);
    }

    private function mirrorTemplateToTargetStore(Device $target, Employee $employee, EmployeeBiometric $template, string $syncState): void
    {
        EmployeeBiometric::query()->updateOrCreate(
            [
                'sync_key' => $this->buildTargetSyncKey($target, $template),
            ],
            [
                'device_id' => $target->id,
                'employee_id' => $employee->id,
                'employee_code' => $employee->employee_id,
                'biometric_type' => $template->biometric_type,
                'template_index' => $template->template_index,
                'file_name' => $template->file_name,
                'is_valid' => $template->is_valid,
                'data_size' => $template->data_size ?: strlen((string) $template->template_data),
                'template_data' => $template->template_data,
                'raw_payload' => [
                    'source_device_id' => $template->device_id,
                    'mirrored_from_sync' => true,
                    'sync_state' => $syncState,
                    'template_transport' => $this->resolveTemplateTransport($template),
                    'face_upload_prefixed' => $this->hasFaceUploadPrefix($template),
                    'synced_at' => now()->toIso8601String(),
                ],
            ]
        );
    }

    private function resolveTemplateTransport(EmployeeBiometric $template): ?string
    {
        $rawPayload = is_array($template->raw_payload) ? $template->raw_payload : [];

        if (($rawPayload['source'] ?? null) === 'udp') {
            return 'udp';
        }

        if (! empty($rawPayload['template_transport'])) {
            return (string) $rawPayload['template_transport'];
        }

        if (array_key_exists('TMP', $rawPayload) || array_key_exists('Content', $rawPayload)) {
            return 'adms_upload';
        }

        return null;
    }

    private function hasFaceUploadPrefix(EmployeeBiometric $template): bool
    {
        if ($template->biometric_type !== EmployeeBiometric::TYPE_FACE) {
            return false;
        }

        $rawPayload = is_array($template->raw_payload) ? $template->raw_payload : [];

        if (array_key_exists('face_upload_prefixed', $rawPayload)) {
            return (bool) $rawPayload['face_upload_prefixed'];
        }

        if (($rawPayload['source'] ?? null) === 'udp') {
            return false;
        }

        return false;
    }

    private function recordReenrollFallback(Device $target, Employee $employee, EmployeeBiometric $template, string $reason, string $fallback): void
    {
        $errorLog = ErrorLog::query()->firstOrCreate([
            'device_id' => $target->id,
            'type' => 'biometric_reenroll_required',
            'error_code' => strtoupper($template->biometric_type).'_REENROLL',
            'message' => sprintf(
                'Biometric transfer was skipped for employee %s (%s) on device %s. Type=%s. Reason=%s. Fallback=%s.',
                $employee->employee_id,
                $employee->full_name,
                $target->name,
                $template->biometric_type,
                $reason,
                $fallback,
            ),
        ], [
            'severity' => 'medium',
            'resolved' => false,
        ]);

        if ($errorLog->wasRecentlyCreated && in_array($fallback, ['notify', 'remote-enroll'], true)) {
            $this->notifyReenrollmentFallback($employee, $target, $template, $reason);
        }

        if ($fallback === 'remote-enroll' && $target->isOnline()) {
            $pin = $this->resolveNumericPin($employee);
            if ($pin === null) {
                return;
            }

            $command = $template->biometric_type === EmployeeBiometric::TYPE_FINGERPRINT
                ? 'ENROLL_FINGERPRINT'
                : 'ENROLL_FACE';

            $parameters = $template->biometric_type === EmployeeBiometric::TYPE_FINGERPRINT
                ? [
                    'pin' => $pin,
                    'fid' => (string) ($template->template_index ?: 0),
                    'retry' => 3,
                    'overwrite' => true,
                ]
                : array_filter([
                    'pin' => $pin,
                    'card_no' => $employee->card_no,
                    'retry' => 3,
                    'overwrite' => true,
                ], static fn ($value) => $value !== null && $value !== '');

            $this->commandService->queueCommand(
                $target->id,
                $command,
                $parameters,
                "Fallback remote re-enrollment requested for employee {$employee->employee_id}",
                [
                    'employee_id' => $employee->id,
                    'force' => true,
                ]
            );
        }
    }

    private function notifyReenrollmentFallback(Employee $employee, Device $target, EmployeeBiometric $template, string $reason): void
    {
        if (! $employee->routeNotificationForMail()) {
            return;
        }

        try {
            $employee->notify(new BiometricReenrollmentRequiredNotification($target, $template, $reason));
        } catch (\Throwable) {
            // Notification failures should not block biometric fallback handling.
        }
    }

    private function upsertTemplateRows(Device $source, array $rows): void
    {
        foreach ($rows as $row) {
            EmployeeBiometric::query()->updateOrCreate(
                ['sync_key' => $row['sync_key']],
                $row,
            );
        }
    }

    private function makeTemplateRow(
        Device $device,
        Employee $employee,
        string $biometricType,
        ?string $templateIndex,
        ?string $fileName,
        ?bool $isValid,
        ?int $dataSize,
        string $templateData,
        array $rawPayload = [],
    ): array {
        return [
            'device_id' => $device->id,
            'employee_id' => $employee->id,
            'employee_code' => $employee->employee_id,
            'biometric_type' => $biometricType,
            'template_index' => $templateIndex,
            'file_name' => $fileName,
            'is_valid' => $isValid,
            'data_size' => $dataSize ?: strlen($templateData),
            'template_data' => $templateData,
            'sync_key' => sha1(json_encode([
                'device_id' => $device->id,
                'employee_code' => $employee->employee_id,
                'biometric_type' => $biometricType,
                'template_index' => $templateIndex,
                'file_name' => $fileName,
            ], JSON_THROW_ON_ERROR)),
            'raw_payload' => $rawPayload,
        ];
    }

    private function buildTargetSyncKey(Device $target, EmployeeBiometric $template): string
    {
        return sha1(json_encode([
            'device_id' => $target->id,
            'employee_code' => $template->employee_code,
            'biometric_type' => $template->biometric_type,
            'template_index' => $template->template_index,
            'file_name' => $template->file_name,
        ], JSON_THROW_ON_ERROR));
    }

    private function unpackLegacyFingerprintTemplate(string $payload): ?array
    {
        if (strlen($payload) < 6) {
            return null;
        }

        $header = unpack('vsize/vpin/Cfinger_id/Cvalid', substr($payload, 0, 6));
        if (! is_array($header)) {
            return null;
        }

        return [
            'size' => (int) ($header['size'] ?? 0),
            'pin' => (string) ($header['pin'] ?? ''),
            'finger_id' => (string) ($header['finger_id'] ?? '0'),
            'valid' => ((int) ($header['valid'] ?? 0)) === 1,
            'template' => substr($payload, 6),
        ];
    }

    public function packLegacyFingerprintTemplate(int $uid, int $fingerIndex, bool $valid, string $templateBytes): string
    {
        $templateBytes = (string) $templateBytes;

        return pack('vvCC', strlen($templateBytes), $uid, $fingerIndex, $valid ? 1 : 0).$templateBytes;
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
                // Some SDKs throw if the socket was never fully established.
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

    private function readAlgorithmVersion(Device $device, string $biometricType): ?string
    {
        return $this->readDeviceHint(
            $device,
            $biometricType === EmployeeBiometric::TYPE_FACE
                ? ['FaceAlgVersion', 'FaceVersion', 'ZKFaceVersion', 'FaceAlgorithmVersion']
                : ['FingerAlgVersion', 'FingerVersion', 'ZKFPVersion', 'FingerprintAlgorithmVersion']
        );
    }

    private function readDeviceHint(Device $device, array $keys): ?string
    {
        $rawConfig = is_array($device->raw_config) ? $device->raw_config : [];

        foreach ($keys as $key) {
            $value = $rawConfig[$key] ?? null;
            $value = trim((string) $value);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function majorVersion(?string $version): ?string
    {
        $version = trim((string) $version);

        if ($version === '') {
            return null;
        }

        if (preg_match('/([0-9]+)(?:\.[0-9]+)?/', $version, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
