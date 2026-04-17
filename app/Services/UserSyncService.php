<?php

namespace App\Services;

use App\DataTransferObjects\SyncableEmployeeData;
use App\Jobs\PrepareUserSyncBatchJob;
use App\Models\Device;
use App\Models\DeviceSync;
use App\Models\Employee;
use App\Repositories\DeviceRepository;
use App\Repositories\DeviceSyncRepository;
use App\Repositories\EmployeeRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserSyncService
{
    public function __construct(
        protected EmployeeRepository $employeeRepository,
        protected DeviceRepository $deviceRepository,
        protected DeviceSyncRepository $deviceSyncRepository,
        protected ZKTecoPayloadParser $payloadParser,
        protected CommandService $commandService,
    ) {}

    public function ingestDeviceUsers(Device $device, string $rawPayload): array
    {
        $parsed = $this->payloadParser->parseUserPayload($rawPayload);
        if ($parsed['records'] === []) {
            throw new \InvalidArgumentException('Malformed user payload');
        }

        $warnings = $parsed['errors'];
        $rows = collect();

        foreach ($parsed['records'] as $record) {
            $employeeId = $this->normalizeNumericIdentifier($record['employee_code'] ?? null);
            $syncUid = $this->normalizeNumericIdentifier($record['uid'] ?? $record['employee_code'] ?? null);
            $syncName = $this->sanitizeSyncName($record['name'] ?? null);

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
                'name' => $syncName,
                'sync_name' => $syncName,
                'status' => 'active',
                'sync_enabled' => true,
                'sync_validation_errors' => null,
                'last_sync_validated_at' => now(),
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
                'name',
                'sync_name',
                'status',
                'sync_enabled',
                'sync_validation_errors',
                'last_sync_validated_at',
                'raw_payload',
                'updated_at',
            ]
        );

        $this->refreshDeviceSafeDataset(
            Employee::query()->whereIn('employee_id', $rows->pluck('employee_id')->all())->get()
        );

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

        foreach ($evaluated as $item) {
            /** @var Employee $employee */
            $employee = $item['employee'];
            $errors = $item['errors'];

            if (in_array($item['sync_uid'], $duplicateUids, true)) {
                $errors[] = 'duplicate_sync_uid';
            }

            $syncEnabled = $errors === [] && $employee->status === 'active';

            if (in_array('legacy_operation_log_record', $errors, true) && config('zkteco.auto_cleanup_legacy_users', false)) {
                $deletedIds[] = $employee->id;
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

        if ($deletedIds !== []) {
            Employee::query()->whereIn('id', $deletedIds)->delete();
        }

        return [
            'total' => $evaluated->count(),
            'sync_safe' => collect($rows)->where('sync_enabled', true)->count(),
            'rejected' => collect($rows)->where('sync_enabled', false)->count(),
            'deleted' => count(array_unique($deletedIds)),
        ];
    }

    public function cleanupLegacyMalformedUsers(): int
    {
        $legacyIds = Employee::query()
            ->where(function ($query): void {
                $query->where('employee_id', 'like', 'OPLOG%')
                    ->orWhere(function ($nested): void {
                        $nested->whereNull('uid')
                            ->whereNotNull('raw_payload')
                            ->where('name', 'like', 'User OPLOG%');
                    });
            })
            ->pluck('id')
            ->all();

        if ($legacyIds === []) {
            return 0;
        }

        return Employee::query()->whereIn('id', $legacyIds)->delete();
    }

    public function queueFullSyncForDevice(Device $device, bool $force = false, string $trigger = 'manual'): ?DeviceSync
    {
        $dataset = $this->deviceSafeDataset();
        if ($dataset->isEmpty()) {
            return null;
        }

        $snapshot = $dataset->map(static fn (SyncableEmployeeData $employee): array => $employee->toSnapshot())
            ->sortBy('employee_id')
            ->values()
            ->all();

        $datasetHash = sha1(json_encode($snapshot, JSON_THROW_ON_ERROR));

        if (! $force) {
            $existing = $this->deviceSyncRepository->findReusableBatch($device->id, DeviceSync::TYPE_USERS, $datasetHash);
            if ($existing) {
                return $existing;
            }
        }

        $sync = DeviceSync::query()->create([
            'device_id' => $device->id,
            'sync_type' => DeviceSync::TYPE_USERS,
            'version' => $this->deviceSyncRepository->nextVersionForDevice($device->id, DeviceSync::TYPE_USERS),
            'dataset_hash' => $datasetHash,
            'status' => DeviceSync::STATUS_QUEUED,
            'total_records' => $dataset->count(),
            'sync_metadata' => [
                'trigger' => $trigger,
                'snapshot' => $snapshot,
            ],
        ]);

        PrepareUserSyncBatchJob::dispatch($sync->id)->onQueue('device-syncs');

        $device->forceFill(['pending_full_resync' => true])->save();

        return $sync;
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

    public function queueGlobalUserSync(bool $force = false, string $trigger = 'manual'): array
    {
        $refreshSummary = $this->refreshDeviceSafeDataset();
        $activeDevices = $this->deviceRepository->getActive();
        $dataset = $this->deviceSafeDataset();

        $syncIds = [];
        $batches = [];
        $newBatchCount = 0;

        foreach ($activeDevices as $device) {
            $beforeId = DeviceSync::query()->max('id') ?? 0;
            $batch = $this->queueFullSyncForDevice($device, $force, $trigger);

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
                'active_device_count' => $activeDevices->count(),
                'safe_user_count' => $dataset->count(),
                'new_batch_count' => $newBatchCount,
                'refresh' => $refreshSummary,
            ],
        ];
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

            $command = $this->commandService->queueCommand(
                $sync->device_id,
                'SYNC_EMPLOYEES',
                [
                    'employee_code' => $entry['employee_id'],
                    'pin' => $entry['sync_uid'],
                    'name' => $entry['sync_name'],
                    'card_no' => $entry['card_no'],
                    'privilege' => 0,
                ],
                'Queued by device sync batch',
                [
                    'device_sync_id' => $sync->id,
                    'employee_id' => $entry['employee_record_id'],
                    'fingerprint' => $fingerprint,
                ]
            );

            if ($command->wasRecentlyCreated) {
                $queuedRecords++;

                continue;
            }

            if ($command->status === \App\Models\DeviceCommand::STATUS_DONE) {
                $processedRecords++;
                $successfulRecords++;
                $skippedFingerprints[] = $fingerprint;
            } elseif (in_array($command->status, [\App\Models\DeviceCommand::STATUS_PENDING, \App\Models\DeviceCommand::STATUS_SENT], true)) {
                if ((int) $command->device_sync_id === $sync->id) {
                    $queuedRecords++;

                    continue;
                }

                $processedRecords++;
                $successfulRecords++;
                $skippedFingerprints[] = $fingerprint;
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
            ->syncSafe()
            ->where('status', 'active')
            ->orderBy('employee_id')
            ->get()
            ->map(function (Employee $employee): SyncableEmployeeData {
                $payloadHash = sha1(json_encode([
                    'employee_id' => $employee->employee_id,
                    'sync_uid' => $employee->sync_uid,
                    'sync_name' => $employee->sync_name,
                    'card_no' => $employee->card_no,
                ], JSON_THROW_ON_ERROR));

                return new SyncableEmployeeData(
                    employeeRecordId: $employee->id,
                    employeeId: $employee->employee_id,
                    syncUid: $employee->sync_uid,
                    syncName: $employee->sync_name,
                    cardNo: $employee->card_no,
                    department: $employee->department,
                    rawPayload: $employee->raw_payload ?? [],
                    payloadHash: $payloadHash,
                );
            });
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

    private function isLegacyMalformedEmployee(Employee $employee): bool
    {
        $employeeId = strtoupper((string) $employee->employee_id);

        if (str_starts_with($employeeId, 'OPLOG')) {
            return true;
        }

        $rawPayload = $employee->raw_payload ?? [];
        $hasNumericEmployeeId = preg_match('/^[0-9]+$/', (string) $employee->employee_id) === 1;

        return ! $hasNumericEmployeeId
            && $employee->uid === null
            && is_array($rawPayload)
            && (
                array_key_exists('Time', $rawPayload)
                || array_key_exists('Status', $rawPayload)
                || array_key_exists('Verify', $rawPayload)
                || array_key_exists('WorkCode', $rawPayload)
            );
    }
}
