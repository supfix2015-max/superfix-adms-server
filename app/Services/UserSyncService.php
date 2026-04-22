<?php

namespace App\Services;

use App\DataTransferObjects\SyncableEmployeeData;
use App\Jobs\PrepareUserSyncBatchJob;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceSync;
use App\Models\Employee;
use App\Models\EmployeeBiometric;
use App\Repositories\DeviceRepository;
use App\Repositories\DeviceSyncRepository;
use App\Repositories\EmployeeRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserSyncService
{
    public const SCOPE_FULL = 'full';

    public const SCOPE_EMPLOYEES = 'employees';

    public const SCOPE_FACE = 'face';

    public const SCOPE_FINGERPRINT = 'fingerprint';

    public function __construct(
        protected EmployeeRepository $employeeRepository,
        protected DeviceRepository $deviceRepository,
        protected DeviceSyncRepository $deviceSyncRepository,
        protected ZKTecoPayloadParser $payloadParser,
        protected CommandService $commandService,
    ) {}

    public function ingestDeviceUsers(Device $device, string $rawPayload, ?array $parsed = null): array
    {
        $parsed ??= $this->payloadParser->parseUserPayload($rawPayload);
        if ($parsed['records'] === []) {
            throw new \InvalidArgumentException('Malformed user payload');
        }

        $warnings = $parsed['errors'];
        $rows = collect();

        foreach ($parsed['records'] as $record) {
            $employeeId = $this->normalizeNumericIdentifier($record['employee_code'] ?? null);
            $syncUid = $this->normalizeNumericIdentifier($record['uid'] ?? $record['employee_code'] ?? null);
            $syncName = $this->sanitizeSyncName($record['name'] ?? null);
            $credentialAttributes = $this->sanitizeCredentialAttributes($record);

            if ($this->isLegacyOperationLogRecord(
                $record['employee_code'] ?? null,
                $record['name'] ?? null,
                is_array($record['raw'] ?? null) ? $record['raw'] : [],
            )) {
                $warnings[] = sprintf('Ignored legacy OPLOG pseudo-user record: %s', json_encode($record, JSON_THROW_ON_ERROR));

                continue;
            }

            if ($employeeId === null || $syncUid === null || $syncName === null) {
                $warnings[] = sprintf('Rejected malformed user record: %s', json_encode($record, JSON_THROW_ON_ERROR));

                continue;
            }

            $rows->push([
                'employee_id' => $employeeId,
                'device_id' => $device->id,
                'uid' => $syncUid,
                'sync_uid' => $syncUid,
                'card_no' => $this->sanitizeCardNumber($record['card_no'] ?? null),
                'device_password' => $credentialAttributes['device_password'],
                'device_privilege' => $credentialAttributes['device_privilege'],
                'group_code' => $credentialAttributes['group_code'],
                'timezone_code' => $credentialAttributes['timezone_code'],
                'verification_mode' => $credentialAttributes['verification_mode'],
                'vice_card_no' => $credentialAttributes['vice_card_no'],
                'valid_from' => $credentialAttributes['valid_from'],
                'valid_until' => $credentialAttributes['valid_until'],
                'name' => $syncName,
                'sync_name' => $syncName,
                'status' => Employee::STATUS_ACTIVE,
                'sync_enabled' => true,
                'sync_validation_errors' => null,
                'last_sync_validated_at' => now(),
                'last_synced_payload_hash' => sha1(json_encode($record['raw'] ?? $record, JSON_THROW_ON_ERROR)),
                'raw_payload' => json_encode($record['raw'] ?? $record, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }

        $duplicatePayloadUids = $rows
            ->groupBy('sync_uid')
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->keys()
            ->all();

        $existingUidConflicts = Employee::query()
            ->whereIn('sync_uid', $rows->pluck('sync_uid')->filter()->all())
            ->get()
            ->keyBy('sync_uid');

        $rows = $rows->reject(function (array $row) use ($duplicatePayloadUids, $existingUidConflicts, &$warnings): bool {
            if (in_array($row['sync_uid'], $duplicatePayloadUids, true)) {
                $warnings[] = "Rejected duplicate sync UID {$row['sync_uid']} from device payload";

                return true;
            }

            $existing = $existingUidConflicts->get($row['sync_uid']);

            if ($existing && $existing->employee_id !== $row['employee_id']) {
                $warnings[] = "Rejected conflicting sync UID {$row['sync_uid']} for employee {$row['employee_id']}";

                return true;
            }

            return false;
        })->unique('employee_id')->values();

        $existingEmployees = Employee::query()
            ->whereIn('employee_id', $rows->pluck('employee_id')->all())
            ->get()
            ->keyBy('employee_id');

        $rows = $rows->map(function (array $row) use ($existingEmployees): array {
            /** @var Employee|null $existingEmployee */
            $existingEmployee = $existingEmployees->get($row['employee_id']);

            if (! $existingEmployee) {
                return $row;
            }

            $incomingName = trim((string) ($row['name'] ?? ''));
            $existingName = trim((string) ($existingEmployee->name ?? ''));
            $existingSyncName = trim((string) ($existingEmployee->sync_name ?? ''));
            $resolvedSyncName = $existingSyncName !== '' ? $existingSyncName : $this->sanitizeSyncName($existingName);

            if ($existingName !== '' && ($incomingName === '' || $this->isGeneratedEmployeeName($incomingName, [
                $row['employee_id'] ?? null,
                $row['sync_uid'] ?? null,
                $existingEmployee->uid,
                $existingEmployee->sync_uid,
            ]))) {
                $row['name'] = $existingName;
                $row['sync_name'] = $resolvedSyncName ?: $existingName;
            }

            return $row;
        })->values();

        if ($rows->isEmpty()) {
            throw new \InvalidArgumentException('No valid user records found');
        }

        Employee::query()->upsert(
            $rows->all(),
            ['employee_id'],
            [
                'device_id',
                'uid',
                'sync_uid',
                'card_no',
                'device_password',
                'device_privilege',
                'group_code',
                'timezone_code',
                'verification_mode',
                'vice_card_no',
                'valid_from',
                'valid_until',
                'name',
                'sync_name',
                'status',
                'sync_enabled',
                'sync_validation_errors',
                'last_sync_validated_at',
                'last_synced_payload_hash',
                'raw_payload',
                'updated_at',
            ]
        );

        $persistedEmployees = Employee::query()
            ->whereIn('employee_id', $rows->pluck('employee_id')->all())
            ->get();

        $this->backfillBiometricEmployeeLinks($persistedEmployees);
        $this->refreshDeviceSafeDataset($persistedEmployees);

        return [
            'count' => $rows->count(),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    public function refreshDeviceSafeDataset(?Collection $employees = null): array
    {
        $employees ??= Employee::query()->whereNull('deleted_at')->get();

        $evaluated = $employees->map(function (Employee $employee): array {
            $errors = [];

            if ($this->isLegacyMalformedEmployee($employee)) {
                $errors[] = 'legacy_operation_log_record';
            }

            $syncUid = $this->normalizeNumericIdentifier($employee->sync_uid ?: $employee->uid ?: $employee->employee_id);
            if ($syncUid === null) {
                $errors[] = 'uid_must_be_numeric';
            }

            $syncName = $this->sanitizeSyncName($employee->sync_name ?: $employee->name ?: $employee->full_name);
            if ($syncName === null) {
                $errors[] = 'name_invalid_for_device';
            }

            return [
                'employee' => $employee,
                'sync_uid' => $syncUid,
                'sync_name' => $syncName,
                'errors' => $errors,
            ];
        })->values();

        $duplicateUids = $evaluated
            ->filter(fn (array $item): bool => $item['sync_uid'] !== null)
            ->groupBy('sync_uid')
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->keys()
            ->all();

        $rows = [];
        $deletedIds = [];
        $deletedCodes = [];

        foreach ($evaluated as $item) {
            /** @var Employee $employee */
            $employee = $item['employee'];
            $errors = $item['errors'];

            if (in_array($item['sync_uid'], $duplicateUids, true)) {
                $errors[] = 'duplicate_sync_uid';
            }

            $syncEnabled = $errors === [] && Employee::isSyncEligibleStatus($employee->status);

            if (in_array('legacy_operation_log_record', $errors, true) && config('zkteco.auto_cleanup_legacy_users', false)) {
                $deletedIds[] = $employee->id;
                $deletedCodes[] = strtoupper(trim((string) $employee->employee_id));
                $syncEnabled = false;
            }

            $rows[] = [
                'id' => $employee->id,
                'sync_uid' => $syncEnabled ? $item['sync_uid'] : null,
                'sync_name' => $syncEnabled ? $item['sync_name'] : null,
                'sync_enabled' => $syncEnabled,
                'sync_validation_errors' => $errors === [] ? null : json_encode(array_values(array_unique($errors)), JSON_THROW_ON_ERROR),
                'last_sync_validated_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (collect($rows)->chunk(500) as $chunk) {
            foreach ($chunk as $row) {
                DB::table('employees')
                    ->where('id', $row['id'])
                    ->update(collect($row)->except('id')->all());
            }
        }

        $deletedSummary = $deletedCodes !== []
            ? $this->purgeLegacyOperationLogArtifacts(array_values(array_unique($deletedCodes)))
            : ['employees' => 0];

        return [
            'total' => $evaluated->count(),
            'sync_safe' => collect($rows)->where('sync_enabled', true)->count(),
            'rejected' => collect($rows)->where('sync_enabled', false)->count(),
            'deleted' => $deletedSummary['employees'] ?? count(array_unique($deletedIds)),
        ];
    }

    public function cleanupLegacyMalformedUsers(): int
    {
        return $this->purgeLegacyOperationLogArtifacts()['employees'];
    }

    public function purgeLegacyOperationLogArtifacts(?array $employeeCodes = null): array
    {
        $legacyEmployees = Employee::withTrashed()
            ->get()
            ->filter(function (Employee $employee) use ($employeeCodes): bool {
                if (! $this->isLegacyMalformedEmployee($employee)) {
                    return false;
                }

                if ($employeeCodes === null || $employeeCodes === []) {
                    return true;
                }

                return in_array(strtoupper(trim((string) $employee->employee_id)), $employeeCodes, true);
            })
            ->values();

        $legacyIds = $legacyEmployees->pluck('id')->all();
        $codes = collect($employeeCodes ?? [])
            ->map(static fn (mixed $code): string => strtoupper(trim((string) $code)))
            ->filter()
            ->merge($legacyEmployees->pluck('employee_id')->map(static fn (mixed $code): string => strtoupper(trim((string) $code))))
            ->unique()
            ->values()
            ->all();

        $deletedAttendance = 0;
        $deletedEmployees = 0;

        if ($codes !== []) {
            $attendanceLogs = \App\Models\AttendanceLog::withTrashed()
                ->get()
                ->filter(function (\App\Models\AttendanceLog $log) use ($codes): bool {
                    if (in_array(strtoupper(trim((string) $log->employee_code)), $codes, true)) {
                        return true;
                    }

                    $rawPayload = is_array($log->raw_payload) ? $log->raw_payload : [];

                    return $this->isLegacyOperationLogRecord(
                        $log->employee_code,
                        $rawPayload['Name'] ?? null,
                        $rawPayload,
                    );
                })
                ->values();

            $deletedAttendance = $attendanceLogs->count();

            $attendanceLogs->each(static function (\App\Models\AttendanceLog $log): void {
                $log->forceDelete();
            });
        }

        if ($legacyIds !== []) {
            $legacyEmployees->each(static function (Employee $employee): void {
                $employee->forceDelete();
            });

            $deletedEmployees = count($legacyIds);
        }

        return [
            'employees' => $deletedEmployees,
            'attendance_logs' => $deletedAttendance,
        ];
    }

    public function isLegacyOperationLogRecord(mixed $employeeCode, ?string $name = null, array $rawPayload = []): bool
    {
        $employeeCode = strtoupper(trim((string) $employeeCode));
        $name = strtoupper(trim((string) $name));
        $rawPin = strtoupper(trim((string) ($rawPayload['PIN'] ?? $rawPayload['USER PIN'] ?? '')));
        $rawName = strtoupper(trim((string) ($rawPayload['Name'] ?? '')));

        if (str_starts_with($name, 'USER OPLOG')) {
            return true;
        }

        if (str_starts_with($employeeCode, 'OPLOG') || str_starts_with($rawPin, 'OPLOG')) {
            return true;
        }

        return str_starts_with($rawName, 'USER OPLOG');
    }

    private function isLegacyMalformedEmployee(Employee $employee): bool
    {
        $rawPayload = is_array($employee->raw_payload) ? $employee->raw_payload : [];

        return $this->isLegacyOperationLogRecord(
            $employee->employee_id,
            $employee->name ?: $employee->sync_name ?: null,
            $rawPayload,
        );
    }

    private function isGeneratedEmployeeName(?string $name, array $identifiers): bool
    {
        $name = Str::of((string) $name)->squish()->lower()->value();

        if ($name === '') {
            return false;
        }

        foreach ($identifiers as $identifier) {
            $identifier = trim((string) $identifier);

            if ($identifier !== '' && $name === Str::of("User {$identifier}")->lower()->value()) {
                return true;
            }
        }

        return false;
    }

    public function queueFullSyncForDevice(Device $device, bool $force = false, string $trigger = 'manual'): ?DeviceSync
    {
        return $this->queueScopedSyncForDevice($device, self::SCOPE_FULL, $force, $trigger);
    }

    public function queueScopedSyncForDevice(
        Device $device,
        string $scope = self::SCOPE_FULL,
        bool $force = false,
        string $trigger = 'manual',
    ): ?DeviceSync
    {
        $prepared = $this->prepareScopedSyncPayload($scope);

        if ($prepared['matching_user_count'] === 0) {
            return null;
        }

        return $this->queuePreparedSyncForDevice($device, $prepared, $force, $trigger);
    }

    public function queueFullSyncForAllDevices(bool $force = false, string $trigger = 'manual'): int
    {
        $queued = 0;

        foreach ($this->deviceRepository->getActive() as $device) {
            if ($this->queueFullSyncForDevice($device, $force, $trigger)) {
                $queued++;
            }
        }

        return $queued;
    }

    public function queueGlobalUserSync(
        bool $force = false,
        string $trigger = 'manual',
        string $scope = self::SCOPE_FULL,
    ): array
    {
        $scope = $this->normalizeSyncScope($scope);
        $refreshSummary = $this->refreshDeviceSafeDataset();
        $activeDevices = $this->deviceRepository->getActive();
        $prepared = $this->prepareScopedSyncPayload($scope);

        $syncIds = [];
        $batches = [];
        $newBatchCount = 0;

        foreach ($activeDevices as $device) {
            $beforeId = DeviceSync::query()->max('id') ?? 0;
            $batch = $this->queuePreparedSyncForDevice($device, $prepared, $force, $trigger);

            if (! $batch) {
                continue;
            }

            $syncIds[] = $batch->id;
            $batches[] = [
                'id' => $batch->id,
                'device_id' => $batch->device_id,
                'status' => $batch->status,
                'total_records' => $batch->total_records,
            ];

            if ($batch->id > $beforeId) {
                $newBatchCount++;
            }
        }

        return [
            'sync_ids' => $syncIds,
            'batches' => $batches,
            'summary' => [
                'scope' => $scope,
                'active_device_count' => $activeDevices->count(),
                'safe_user_count' => $prepared['safe_user_count'],
                'matching_user_count' => $prepared['matching_user_count'],
                'matching_biometric_count' => $prepared['matching_biometric_count'],
                'new_batch_count' => $newBatchCount,
                'refresh' => $refreshSummary,
            ],
        ];
    }

    public function syncScopeLabel(string $scope): string
    {
        return match ($this->normalizeSyncScope($scope)) {
            self::SCOPE_EMPLOYEES => 'employee',
            self::SCOPE_FACE => 'face template',
            self::SCOPE_FINGERPRINT => 'fingerprint template',
            default => 'user and biometric',
        };
    }

    private function queuePreparedSyncForDevice(
        Device $device,
        array $prepared,
        bool $force = false,
        string $trigger = 'manual',
    ): ?DeviceSync
    {
        if (($prepared['matching_user_count'] ?? 0) === 0) {
            return null;
        }

        $scope = $prepared['scope'];
        $snapshot = $prepared['snapshot'];
        $datasetHash = sha1(json_encode([
            'scope' => $scope,
            'snapshot' => $snapshot,
        ], JSON_THROW_ON_ERROR));

        if (! $force) {
            $existing = $this->deviceSyncRepository->findReusableBatch($device->id, DeviceSync::TYPE_USERS, $datasetHash);
            if ($existing) {
                $existing = $this->reconcileReusableBatch($existing);

                if (
                    $existing->status === DeviceSync::STATUS_QUEUED
                    && ! DeviceCommand::query()->where('device_sync_id', $existing->id)->exists()
                ) {
                    $this->dispatchQueuedBatch($existing->id);
                    $existing = $existing->fresh();
                }

                if (in_array($existing->status, [
                    DeviceSync::STATUS_QUEUED,
                    DeviceSync::STATUS_DISPATCHING,
                    DeviceSync::STATUS_COMPLETED,
                ], true)) {
                    return $existing;
                }
            }
        }

        $sync = DeviceSync::query()->create([
            'device_id' => $device->id,
            'sync_type' => DeviceSync::TYPE_USERS,
            'version' => $this->deviceSyncRepository->nextVersionForDevice($device->id, DeviceSync::TYPE_USERS),
            'dataset_hash' => $datasetHash,
            'status' => DeviceSync::STATUS_QUEUED,
            'total_records' => $prepared['total_records'],
            'sync_metadata' => [
                'trigger' => $trigger,
                'scope' => $scope,
                'snapshot' => $snapshot,
                'matching_user_count' => $prepared['matching_user_count'],
                'matching_biometric_count' => $prepared['matching_biometric_count'],
            ],
        ]);

        PrepareUserSyncBatchJob::dispatch($sync->id)->onQueue('device-syncs');

        $device->forceFill(['pending_full_resync' => true])->save();

        return $sync;
    }

    public function syncBatchStatus(array $syncIds): array
    {
        $batches = DeviceSync::query()
            ->whereIn('id', $syncIds)
            ->orderBy('id')
            ->get([
                'id',
                'device_id',
                'status',
                'total_records',
                'queued_records',
                'processed_records',
                'successful_records',
                'failed_records',
                'sync_started_at',
                'sync_completed_at',
                'error_message',
            ]);

        return [
            'sync_ids' => $batches->pluck('id')->all(),
            'batches' => $batches->map(static fn (DeviceSync $batch): array => [
                'id' => $batch->id,
                'device_id' => $batch->device_id,
                'status' => $batch->status,
                'total_records' => $batch->total_records,
                'queued_records' => $batch->queued_records,
                'processed_records' => $batch->processed_records,
                'successful_records' => $batch->successful_records,
                'failed_records' => $batch->failed_records,
                'sync_started_at' => $batch->sync_started_at?->toIso8601String(),
                'sync_completed_at' => $batch->sync_completed_at?->toIso8601String(),
                'error_message' => $batch->error_message,
            ])->all(),
        ];
    }

    private function prepareScopedSyncPayload(string $scope): array
    {
        $scope = $this->normalizeSyncScope($scope);
        $dataset = $this->deviceSafeDataset();
        $snapshot = $dataset
            ->map(fn (SyncableEmployeeData $employee): ?array => $this->buildScopedSnapshotEntry($employee, $scope))
            ->filter()
            ->sortBy('employee_id')
            ->values()
            ->all();

        return [
            'scope' => $scope,
            'snapshot' => $snapshot,
            'safe_user_count' => $dataset->count(),
            'matching_user_count' => count($snapshot),
            'matching_biometric_count' => collect($snapshot)->sum(
                static fn (array $entry): int => count($entry['biometrics'] ?? [])
            ),
            'total_records' => collect($snapshot)->sum(
                static fn (array $entry): int => 1 + count($entry['biometrics'] ?? [])
            ),
        ];
    }

    private function buildScopedSnapshotEntry(SyncableEmployeeData $employee, string $scope): ?array
    {
        $biometrics = match ($scope) {
            self::SCOPE_EMPLOYEES => [],
            self::SCOPE_FACE => array_values(array_filter(
                $employee->biometrics,
                static fn (array $biometric): bool => ($biometric['biometric_type'] ?? null) === EmployeeBiometric::TYPE_FACE,
            )),
            self::SCOPE_FINGERPRINT => array_values(array_filter(
                $employee->biometrics,
                static fn (array $biometric): bool => ($biometric['biometric_type'] ?? null) === EmployeeBiometric::TYPE_FINGERPRINT,
            )),
            default => $employee->biometrics,
        };

        if (in_array($scope, [self::SCOPE_FACE, self::SCOPE_FINGERPRINT], true) && $biometrics === []) {
            return null;
        }

        return [
            'employee_record_id' => $employee->employeeRecordId,
            'employee_id' => $employee->employeeId,
            'sync_uid' => $employee->syncUid,
            'sync_name' => $employee->syncName,
            'card_no' => $employee->cardNo,
            'device_password' => $employee->devicePassword,
            'device_privilege' => $employee->devicePrivilege,
            'group_code' => $employee->groupCode,
            'timezone_code' => $employee->timezoneCode,
            'verification_mode' => $employee->verificationMode,
            'vice_card_no' => $employee->viceCardNo,
            'valid_from' => $employee->validFrom,
            'valid_until' => $employee->validUntil,
            'biometrics' => $biometrics,
            'department' => $employee->department,
            'payload_hash' => sha1(json_encode([
                'scope' => $scope,
                'employee_id' => $employee->employeeId,
                'sync_uid' => $employee->syncUid,
                'sync_name' => $employee->syncName,
                'card_no' => $employee->cardNo,
                'device_password' => $employee->devicePassword,
                'device_privilege' => $employee->devicePrivilege,
                'group_code' => $employee->groupCode,
                'timezone_code' => $employee->timezoneCode,
                'verification_mode' => $employee->verificationMode,
                'vice_card_no' => $employee->viceCardNo,
                'valid_from' => $employee->validFrom,
                'valid_until' => $employee->validUntil,
                'biometrics' => array_map(static fn (array $biometric): array => [
                    'biometric_type' => $biometric['biometric_type'] ?? null,
                    'template_index' => $biometric['template_index'] ?? null,
                    'file_name' => $biometric['file_name'] ?? null,
                    'template_hash' => sha1((string) ($biometric['template_data'] ?? '')),
                ], $biometrics),
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    private function normalizeSyncScope(string $scope): string
    {
        return match (strtolower(trim($scope))) {
            self::SCOPE_EMPLOYEES => self::SCOPE_EMPLOYEES,
            self::SCOPE_FACE => self::SCOPE_FACE,
            self::SCOPE_FINGERPRINT => self::SCOPE_FINGERPRINT,
            default => self::SCOPE_FULL,
        };
    }

    public function dispatchQueuedBatch(int $deviceSyncId): void
    {
        /** @var DeviceSync $sync */
        $sync = DeviceSync::query()->with('device')->findOrFail($deviceSyncId);
        $snapshot = collect($sync->sync_metadata['snapshot'] ?? []);

        if ($snapshot->isEmpty()) {
            $sync->forceFill([
                'status' => DeviceSync::STATUS_FAILED,
                'error_message' => 'Sync snapshot is empty',
                'sync_completed_at' => now(),
            ])->save();

            return;
        }

        $queuedRecords = 0;
        $processedRecords = 0;
        $successfulRecords = 0;
        $skippedFingerprints = [];

        foreach ($snapshot as $entry) {
            $fingerprint = sha1(json_encode([
                'device_id' => $sync->device_id,
                'employee_id' => $entry['employee_id'],
                'sync_uid' => $entry['sync_uid'],
                'sync_name' => $entry['sync_name'],
                'card_no' => $entry['card_no'],
                'payload_hash' => $entry['payload_hash'],
            ], JSON_THROW_ON_ERROR));

            $this->queueBatchCommand(
                $sync,
                'SYNC_EMPLOYEES',
                [
                    'employee_code' => $entry['employee_id'],
                    'pin' => $entry['sync_uid'],
                    'name' => $entry['sync_name'],
                    'card_no' => $entry['card_no'],
                    'password' => $entry['device_password'],
                    'privilege' => $entry['device_privilege'],
                    'group_code' => $entry['group_code'],
                    'timezone_code' => $entry['timezone_code'],
                    'verification_mode' => $entry['verification_mode'],
                    'vice_card_no' => $entry['vice_card_no'],
                    'valid_from' => $entry['valid_from'],
                    'valid_until' => $entry['valid_until'],
                ],
                $entry['employee_record_id'],
                $fingerprint,
                $queuedRecords,
                $processedRecords,
                $successfulRecords,
                $skippedFingerprints,
            );

            foreach ($entry['biometrics'] ?? [] as $biometric) {
                $biometricFingerprint = sha1(json_encode([
                    'device_id' => $sync->device_id,
                    'employee_id' => $entry['employee_id'],
                    'sync_uid' => $entry['sync_uid'],
                    'biometric_type' => $biometric['biometric_type'] ?? null,
                    'template_index' => $biometric['template_index'] ?? null,
                    'file_name' => $biometric['file_name'] ?? null,
                    'template_hash' => sha1((string) ($biometric['template_data'] ?? '')),
                ], JSON_THROW_ON_ERROR));

                $this->queueBatchCommand(
                    $sync,
                    'SYNC_BIOMETRICS',
                    [
                        'employee_code' => $entry['employee_id'],
                        'pin' => $entry['sync_uid'],
                        'biometric_type' => $biometric['biometric_type'] ?? null,
                        'template_index' => $biometric['template_index'] ?? null,
                        'file_name' => $biometric['file_name'] ?? null,
                        'data_size' => $biometric['data_size'] ?? null,
                        'is_valid' => $biometric['is_valid'] ?? null,
                        'template_data' => $biometric['template_data'] ?? null,
                        'template_transport' => $biometric['template_transport'] ?? null,
                        'face_upload_prefixed' => $biometric['face_upload_prefixed'] ?? null,
                    ],
                    $entry['employee_record_id'],
                    $biometricFingerprint,
                    $queuedRecords,
                    $processedRecords,
                    $successfulRecords,
                    $skippedFingerprints,
                );
            }
        }

        $sync->forceFill([
            'status' => $queuedRecords > 0 ? DeviceSync::STATUS_DISPATCHING : DeviceSync::STATUS_COMPLETED,
            'queued_records' => $queuedRecords,
            'processed_records' => $processedRecords,
            'successful_records' => $successfulRecords,
            'sync_started_at' => $queuedRecords > 0 ? now() : ($sync->sync_started_at ?: now()),
            'sync_completed_at' => $queuedRecords === 0 ? now() : null,
            'sync_metadata' => array_merge($sync->sync_metadata ?? [], [
                'skipped_fingerprints' => $skippedFingerprints,
            ]),
        ])->save();

        if ($queuedRecords === 0) {
            $sync->device->forceFill([
                'last_user_sync_version' => $sync->version,
                'last_user_sync_at' => now(),
                'pending_full_resync' => false,
            ])->save();
        }
    }

    public function deviceSafeDataset(): Collection
    {
        return Employee::query()
            ->with([
                'biometrics' => function ($query): void {
                    $query->orderBy('biometric_type')
                        ->orderBy('template_index')
                        ->orderBy('file_name')
                        ->orderBy('id');
                },
            ])
            ->syncSafe()
            ->whereIn('status', Employee::syncEligibleStatuses())
            ->orderBy('employee_id')
            ->get()
            ->map(function (Employee $employee): SyncableEmployeeData {
                $credentialAttributes = $this->resolveCredentialAttributesFromEmployee($employee);
                $biometrics = $this->buildBiometricSyncEntries($employee);

                $payloadHash = sha1(json_encode([
                    'employee_id' => $employee->employee_id,
                    'sync_uid' => $employee->sync_uid,
                    'sync_name' => $employee->sync_name,
                    'card_no' => $employee->card_no,
                    'device_password' => $credentialAttributes['device_password'],
                    'device_privilege' => $credentialAttributes['device_privilege'],
                    'group_code' => $credentialAttributes['group_code'],
                    'timezone_code' => $credentialAttributes['timezone_code'],
                    'verification_mode' => $credentialAttributes['verification_mode'],
                    'vice_card_no' => $credentialAttributes['vice_card_no'],
                    'valid_from' => $credentialAttributes['valid_from'],
                    'valid_until' => $credentialAttributes['valid_until'],
                    'biometrics' => array_map(static fn (array $biometric): array => [
                        'biometric_type' => $biometric['biometric_type'],
                        'template_index' => $biometric['template_index'],
                        'file_name' => $biometric['file_name'],
                        'data_size' => $biometric['data_size'],
                        'is_valid' => $biometric['is_valid'],
                        'template_hash' => sha1((string) ($biometric['template_data'] ?? '')),
                    ], $biometrics),
                ], JSON_THROW_ON_ERROR));

                return new SyncableEmployeeData(
                    employeeRecordId: $employee->id,
                    employeeId: $employee->employee_id,
                    syncUid: $employee->sync_uid,
                    syncName: $employee->sync_name,
                    cardNo: $employee->card_no,
                    devicePassword: $credentialAttributes['device_password'],
                    devicePrivilege: $credentialAttributes['device_privilege'],
                    groupCode: $credentialAttributes['group_code'],
                    timezoneCode: $credentialAttributes['timezone_code'],
                    verificationMode: $credentialAttributes['verification_mode'],
                    viceCardNo: $credentialAttributes['vice_card_no'],
                    validFrom: $credentialAttributes['valid_from'],
                    validUntil: $credentialAttributes['valid_until'],
                    biometrics: $biometrics,
                    department: $employee->department,
                    rawPayload: $employee->raw_payload ?? [],
                    payloadHash: $payloadHash,
                );
            });
    }

    private function queueBatchCommand(
        DeviceSync $sync,
        string $commandName,
        array $parameters,
        int $employeeRecordId,
        string $fingerprint,
        int &$queuedRecords,
        int &$processedRecords,
        int &$successfulRecords,
        array &$skippedFingerprints,
    ): void {
        $command = $this->commandService->queueCommand(
            $sync->device_id,
            $commandName,
            $parameters,
            'Queued by device sync batch',
            [
                'device_sync_id' => $sync->id,
                'employee_id' => $employeeRecordId,
                'fingerprint' => $fingerprint,
            ]
        );

        if ($command->wasRecentlyCreated) {
            $queuedRecords++;

            return;
        }

        if ($command->status === \App\Models\DeviceCommand::STATUS_DONE) {
            $processedRecords++;
            $successfulRecords++;
            $skippedFingerprints[] = $fingerprint;

            return;
        }

        if (in_array($command->status, [\App\Models\DeviceCommand::STATUS_PENDING, \App\Models\DeviceCommand::STATUS_SENT], true)) {
            if ((int) $command->device_sync_id === $sync->id) {
                $queuedRecords++;

                return;
            }

            $processedRecords++;
            $successfulRecords++;
            $skippedFingerprints[] = $fingerprint;
        }
    }

    private function reconcileReusableBatch(DeviceSync $sync): DeviceSync
    {
        $statuses = DeviceCommand::query()
            ->where('device_sync_id', $sync->id)
            ->pluck('status');

        if ($statuses->isEmpty()) {
            if ($sync->status === DeviceSync::STATUS_DISPATCHING) {
                $sync->forceFill([
                    'status' => DeviceSync::STATUS_QUEUED,
                    'queued_records' => 0,
                    'processed_records' => 0,
                    'successful_records' => 0,
                    'failed_records' => 0,
                    'sync_started_at' => null,
                    'sync_completed_at' => null,
                ])->save();
            }

            return $sync->fresh();
        }

        $queuedRecords = $statuses->count();
        $successfulRecords = $statuses->filter(
            static fn (string $status): bool => $status === DeviceCommand::STATUS_DONE
        )->count();
        $failedRecords = $statuses->filter(
            static fn (string $status): bool => $status === DeviceCommand::STATUS_FAILED
        )->count();
        $processedRecords = $successfulRecords + $failedRecords;

        $attributes = [
            'queued_records' => $queuedRecords,
            'processed_records' => $processedRecords,
            'successful_records' => $successfulRecords,
            'failed_records' => $failedRecords,
        ];

        if ($processedRecords >= $queuedRecords) {
            $attributes['sync_completed_at'] = $sync->sync_completed_at ?: now();
            $attributes['status'] = $failedRecords > 0
                ? DeviceSync::STATUS_COMPLETED_WITH_ERRORS
                : DeviceSync::STATUS_COMPLETED;
        } else {
            $attributes['status'] = DeviceSync::STATUS_DISPATCHING;
            $attributes['sync_started_at'] = $sync->sync_started_at ?: $sync->last_dispatched_at ?: now();
            $attributes['sync_completed_at'] = null;
        }

        $sync->forceFill($attributes)->save();

        if (($attributes['status'] ?? null) === DeviceSync::STATUS_COMPLETED) {
            $sync->loadMissing('device');
            $sync->device?->forceFill([
                'last_user_sync_version' => $sync->version,
                'last_user_sync_at' => now(),
                'pending_full_resync' => false,
            ])->save();
        }

        return $sync->fresh();
    }

    private function buildBiometricSyncEntries(Employee $employee): array
    {
        return $employee->biometrics
            ->map(function (EmployeeBiometric $biometric) use ($employee): ?array {
                $biometricType = strtolower(trim((string) $biometric->biometric_type));

                if (! in_array($biometricType, [
                    EmployeeBiometric::TYPE_FINGERPRINT,
                    EmployeeBiometric::TYPE_FACE,
                    EmployeeBiometric::TYPE_PHOTO,
                ], true)) {
                    return null;
                }

                $templateData = trim((string) ($biometric->template_data ?? ''));
                if ($templateData === '') {
                    return null;
                }

                $templateIndex = $this->sanitizeDeviceCode($biometric->template_index, 32);
                $fileName = $this->sanitizeBiometricFileName($biometric->file_name);

                if ($biometricType === EmployeeBiometric::TYPE_PHOTO && $fileName === null) {
                    $fileName = "{$employee->employee_id}.jpg";
                }

                return [
                    'biometric_type' => $biometricType,
                    'template_index' => $templateIndex,
                    'file_name' => $fileName,
                    'data_size' => $biometric->data_size ?: strlen($templateData),
                    'is_valid' => $biometric->is_valid,
                    'template_data' => $templateData,
                    'template_transport' => $this->resolveBiometricTemplateTransport($biometric),
                    'face_upload_prefixed' => $this->hasFaceUploadPrefix($biometric),
                ];
            })
            ->filter()
            ->unique(static fn (array $entry): string => sha1(json_encode([
                'biometric_type' => $entry['biometric_type'],
                'template_index' => $entry['template_index'],
                'file_name' => $entry['file_name'],
                'template_hash' => sha1((string) ($entry['template_data'] ?? '')),
            ], JSON_THROW_ON_ERROR)))
            ->sortBy(static fn (array $entry): string => implode('|', [
                match ($entry['biometric_type']) {
                    EmployeeBiometric::TYPE_FINGERPRINT => '0',
                    EmployeeBiometric::TYPE_FACE => '1',
                    EmployeeBiometric::TYPE_PHOTO => '2',
                    default => '9',
                },
                $entry['template_index'] ?? '',
                $entry['file_name'] ?? '',
                sha1((string) ($entry['template_data'] ?? '')),
            ]))
            ->values()
            ->all();
    }

    private function resolveBiometricTemplateTransport(EmployeeBiometric $biometric): ?string
    {
        $rawPayload = is_array($biometric->raw_payload) ? $biometric->raw_payload : [];

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

    private function hasFaceUploadPrefix(EmployeeBiometric $biometric): bool
    {
        if ($biometric->biometric_type !== EmployeeBiometric::TYPE_FACE) {
            return false;
        }

        $rawPayload = is_array($biometric->raw_payload) ? $biometric->raw_payload : [];

        if (array_key_exists('face_upload_prefixed', $rawPayload)) {
            return (bool) $rawPayload['face_upload_prefixed'];
        }

        if (($rawPayload['source'] ?? null) === 'udp') {
            return false;
        }

        return false;
    }

    private function backfillBiometricEmployeeLinks(Collection $employees): int
    {
        $employeesByCode = $employees
            ->filter(static fn (Employee $employee): bool => trim((string) $employee->employee_id) !== '')
            ->keyBy(static fn (Employee $employee): string => (string) $employee->employee_id);

        if ($employeesByCode->isEmpty()) {
            return 0;
        }

        $updated = 0;

        EmployeeBiometric::query()
            ->whereIn('employee_code', $employeesByCode->keys()->all())
            ->get()
            ->each(function (EmployeeBiometric $biometric) use ($employeesByCode, &$updated): void {
                $employee = $employeesByCode->get((string) $biometric->employee_code);

                if (! $employee || (int) $biometric->employee_id === (int) $employee->id) {
                    return;
                }

                $biometric->forceFill([
                    'employee_id' => $employee->id,
                ])->save();

                $updated++;
            });

        return $updated;
    }

    private function normalizeNumericIdentifier(mixed $value): ?string
    {
        $value = preg_replace('/[^0-9]/', '', trim((string) $value));

        return $value !== '' ? substr($value, 0, 32) : null;
    }

    private function sanitizeSyncName(mixed $value): ?string
    {
        $name = trim((string) $value);
        $name = Str::ascii($name);
        $name = preg_replace('/[^A-Za-z0-9 ._-]/', ' ', $name);
        $name = preg_replace('/\s+/', ' ', trim((string) $name));

        if ($name === '') {
            return null;
        }

        return Str::limit($name, (int) config('zkteco.sync_name_max_length', 24), '');
    }

    private function sanitizeCardNumber(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $value = preg_replace('/[^A-Za-z0-9_-]/', '', $value);

        return $value !== '' ? substr($value, 0, 32) : null;
    }

    private function sanitizeCredentialAttributes(array $record): array
    {
        $rawPayload = is_array($record['raw'] ?? null) ? $record['raw'] : [];

        return [
            'device_password' => $this->sanitizeDevicePassword($record['password'] ?? $rawPayload['Passwd'] ?? $rawPayload['Password'] ?? null),
            'device_privilege' => $this->normalizePrivilege($record['privilege'] ?? $rawPayload['Pri'] ?? $rawPayload['Privilege'] ?? null),
            'group_code' => $this->sanitizeDeviceCode($record['group_code'] ?? $rawPayload['Grp'] ?? $rawPayload['Group'] ?? null, 32),
            'timezone_code' => $this->sanitizeDeviceCode($record['timezone_code'] ?? $rawPayload['TZ'] ?? $rawPayload['Timezone'] ?? null, 32),
            'verification_mode' => $this->sanitizeDeviceCode($record['verification_mode'] ?? $rawPayload['Verify'] ?? $rawPayload['VerifyType'] ?? null, 32),
            'vice_card_no' => $this->sanitizeDeviceCode($record['vice_card_no'] ?? $rawPayload['ViceCard'] ?? $rawPayload['ViceCardNo'] ?? null, 64),
            'valid_from' => $this->normalizeDeviceDateTimeValue($record['valid_from'] ?? $rawPayload['StartDatetime'] ?? $rawPayload['StartTime'] ?? null),
            'valid_until' => $this->normalizeDeviceDateTimeValue($record['valid_until'] ?? $rawPayload['EndDatetime'] ?? $rawPayload['EndTime'] ?? null),
        ];
    }

    private function sanitizeDevicePassword(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || $value === '0') {
            return null;
        }

        $value = Str::ascii($value);
        $value = preg_replace('/[^A-Za-z0-9._@#%*!$:-]/', '', $value);

        return $value !== '' ? substr($value, 0, 64) : null;
    }

    private function sanitizeDeviceCode(mixed $value, int $maxLength = 32): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || $value === '0') {
            return null;
        }

        $value = Str::ascii($value);
        $value = preg_replace('/[^A-Za-z0-9._:-]/', '', $value);

        return $value !== '' ? substr($value, 0, $maxLength) : null;
    }

    private function sanitizeBiometricFileName(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $value = Str::ascii($value);
        $value = preg_replace('/[^A-Za-z0-9._-]/', '', $value);

        return $value !== '' ? substr($value, 0, 255) : null;
    }

    private function normalizePrivilege(mixed $value): int
    {
        $value = trim((string) $value);

        if ($value === '' || preg_match('/^-?[0-9]+$/', $value) !== 1) {
            return 0;
        }

        return max(0, min((int) $value, 14));
    }

    private function normalizeDeviceDateTimeValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $value = trim((string) $value);

        if ($value === '' || $value === '0') {
            return null;
        }

        return $this->payloadParser->normalizeTimestamp($value)?->format('Y-m-d H:i:s');
    }

    private function resolveCredentialAttributesFromEmployee(Employee $employee): array
    {
        return $this->sanitizeCredentialAttributes([
            'password' => $employee->device_password,
            'privilege' => $employee->device_privilege,
            'group_code' => $employee->group_code,
            'timezone_code' => $employee->timezone_code,
            'verification_mode' => $employee->verification_mode,
            'vice_card_no' => $employee->vice_card_no,
            'valid_from' => $employee->valid_from,
            'valid_until' => $employee->valid_until,
            'raw' => $employee->raw_payload ?? [],
        ]);
    }


}
