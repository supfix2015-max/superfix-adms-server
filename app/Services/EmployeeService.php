<?php

namespace App\Services;

use App\Models\BiometricTemplate;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\EmployeeBiometric;
use App\Repositories\EmployeeRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmployeeService
{
    public function __construct(
        protected EmployeeRepository $repository,
        protected CommandService $commandService,
    ) {}

    public function createEmployee(array $data): Employee
    {
        return $this->repository->create($data);
    }

    public function updateEmployeeProfile(Employee $employee, array $data): Employee
    {
        $targetStatus = Employee::normalizeStatus((string) ($data['status'] ?? $employee->status))
            ?? Employee::normalizeStatus((string) $employee->status)
            ?? Employee::STATUS_ACTIVE;

        $data['status'] = $targetStatus;
        $data = $this->applyLifecycleTimestamps($employee, $data, $targetStatus);

        if (Employee::shouldRemoveFromDevices($targetStatus)) {
            $this->archiveEmployee($employee, $targetStatus, $data);

            return $employee->fresh();
        }

        $this->repository->update($employee->id, $data);

        return $employee->fresh();
    }

    public function getEmployee(int $employeeId): ?Employee
    {
        return $this->repository->find($employeeId);
    }

    public function getEmployeeByCode(string $employeeCode): ?Employee
    {
        return $this->repository->findByEmployeeId($employeeCode);
    }

    public function getEmployeeByEmail(string $email): ?Employee
    {
        return $this->repository->findByEmail($email);
    }

    public function getAllEmployees()
    {
        return $this->repository->all();
    }

    public function getActiveEmployees()
    {
        return $this->repository->getActive();
    }

    public function addBiometricId(int $employeeId, int $deviceId, int $biometricId): bool
    {
        return $this->repository->addBiometricId($employeeId, $deviceId, $biometricId);
    }

    public function getBiometricIds(int $employeeId, int $deviceId): array
    {
        return $this->repository->getBiometricId($employeeId, $deviceId);
    }

    public function activateEmployee(int $employeeId): bool
    {
        $employee = $this->getEmployee($employeeId);

        if (! $employee) {
            return false;
        }

        $this->updateEmployeeProfile($employee, ['status' => Employee::STATUS_ACTIVE]);

        return true;
    }

    public function deactivateEmployee(int $employeeId): bool
    {
        $employee = $this->getEmployee($employeeId);

        if (! $employee) {
            return false;
        }

        $this->updateEmployeeProfile($employee, ['status' => Employee::STATUS_INACTIVE]);

        return true;
    }

    public function suspendEmployee(int $employeeId): bool
    {
        $employee = $this->getEmployee($employeeId);

        if (! $employee) {
            return false;
        }

        $this->updateEmployeeProfile($employee, ['status' => Employee::STATUS_SUSPENDED]);

        return true;
    }

    public function getDepartments()
    {
        return $this->repository->getDepartments();
    }

    public function getEmployeeStatistics(): array
    {
        return $this->repository->getStatistics();
    }

    public function deleteEmployee(int $employeeId): array
    {
        /** @var Employee|null $employee */
        $employee = Employee::query()->find($employeeId);

        if (! $employee) {
            throw new \InvalidArgumentException('Employee not found.');
        }

        return $this->archiveEmployee($employee, Employee::STATUS_TERMINATED);
    }

    public function importEmployees(array $employees): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($employees as $index => $employeeData) {
            try {
                if (isset($employeeData['status'])) {
                    $employeeData['status'] = Employee::normalizeStatus((string) $employeeData['status']) ?? Employee::STATUS_ACTIVE;
                }

                // Check if employee already exists by ID
                $existing = $this->repository->findByEmployeeId($employeeData['employee_id']);
                if ($existing) {
                    $this->repository->update($existing->id, $employeeData);
                    $results['success']++;
                } else {
                    $this->repository->create($employeeData);
                    $results['success']++;
                }
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'row' => $index + 1,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    private function archiveEmployee(Employee $employee, string $status, array $attributes = []): array
    {
        $devicePin = $this->resolveDevicePin($employee);
        $deviceTargets = 0;
        $queuedDeviceCommands = 0;
        $deletedPendingCommands = 0;
        $originalEmployeeId = $employee->employee_id;
        $originalEmployeeName = $employee->full_name;
        $attributes['status'] = $status;
        $attributes = $this->applyLifecycleTimestamps($employee, $attributes, $status);

        DB::transaction(function () use (
            $employee,
            $devicePin,
            $attributes,
            &$deviceTargets,
            &$queuedDeviceCommands,
            &$deletedPendingCommands,
        ): void {
            $deletedPendingCommands = $this->deletePendingEmployeeCommands($employee, $devicePin);
            [$deviceTargets, $queuedDeviceCommands] = $this->queueDeviceDeletionCommands($employee, $devicePin);
            $this->cleanupStoredEmployeeData($employee, true);

            $employee->forceFill(array_merge($attributes, [
                'device_id' => null,
                'employee_id' => null,
                'uid' => null,
                'sync_uid' => null,
                'sync_enabled' => false,
                'sync_validation_errors' => null,
                'last_sync_validated_at' => now(),
            ]))->save();
        });

        return [
            'employee_id' => $originalEmployeeId,
            'employee_name' => $originalEmployeeName,
            'device_pin' => $devicePin,
            'device_targets' => $deviceTargets,
            'queued_device_commands' => $queuedDeviceCommands,
            'deleted_pending_commands' => $deletedPendingCommands,
            'status' => $status,
            'retained_record_id' => $employee->id,
        ];
    }

    private function deletePendingEmployeeCommands(Employee $employee, ?string $devicePin): int
    {
        $query = DeviceCommand::query()
            ->whereIn('status', [
                DeviceCommand::STATUS_PENDING,
                DeviceCommand::STATUS_SENT,
            ])
            ->where(function ($builder) use ($employee, $devicePin): void {
                $builder->where('employee_id', $employee->id)
                    ->orWhere('parameters->employee_code', $employee->employee_id);

                if ($devicePin !== null) {
                    $builder->orWhere('parameters->pin', $devicePin);
                }
            });

        $count = (clone $query)->count();

        if ($count > 0) {
            $query->delete();
        }

        return $count;
    }

    private function queueDeviceDeletionCommands(Employee $employee, ?string $devicePin): array
    {
        if ($devicePin === null) {
            return [0, 0];
        }

        $devices = Device::query()->orderBy('id')->get();
        $commandTemplates = array_values(array_filter(
            config('zkteco.delete_employee_commands', []),
            static fn (mixed $template): bool => is_string($template) && trim($template) !== ''
        ));

        if ($devices->isEmpty() || $commandTemplates === []) {
            return [$devices->count(), 0];
        }

        $queued = 0;

        foreach ($devices as $device) {
            foreach ($commandTemplates as $template) {
                $payload = $this->interpolateDeleteCommandTemplate($template, $employee, $devicePin);

                if ($payload === null) {
                    continue;
                }

                $this->commandService->queueCommand(
                    $device->id,
                    $payload,
                    [],
                    sprintf(
                        'Delete employee %s (%s) from device after local removal',
                        $employee->full_name,
                        $employee->employee_id
                    ),
                    [
                        'force' => true,
                    ],
                );

                $queued++;
            }
        }

        return [$devices->count(), $queued];
    }

    private function cleanupStoredEmployeeData(Employee $employee, bool $preserveAttendanceLinks = false): void
    {
        EmployeeBiometric::query()
            ->where(function ($builder) use ($employee): void {
                $builder->where('employee_id', $employee->id)
                    ->orWhere('employee_code', $employee->employee_id);
            })
            ->delete();

        if (Schema::hasTable('biometric_templates')) {
            BiometricTemplate::query()
                ->where('employee_id', $employee->id)
                ->delete();
        }

        if (! $preserveAttendanceLinks && Schema::hasTable('attendance_logs')) {
            DB::table('attendance_logs')
                ->where('employee_id', $employee->id)
                ->update([
                    'employee_id' => null,
                    'updated_at' => now(),
                ]);
        }
    }

    private function interpolateDeleteCommandTemplate(string $template, Employee $employee, string $devicePin): ?string
    {
        $replacements = [
            '{pin}' => $this->sanitizeCommandValue($devicePin),
            '{employee_id}' => $this->sanitizeCommandValue((string) $employee->employee_id),
            '{uid}' => $this->sanitizeCommandValue((string) ($employee->uid ?? '')),
            '{sync_uid}' => $this->sanitizeCommandValue((string) ($employee->sync_uid ?? '')),
            '{card_no}' => $this->sanitizeCommandValue((string) ($employee->card_no ?? '')),
        ];

        $payload = strtr($template, $replacements);

        if (preg_match('/\{[a-z_]+\}/i', $payload) === 1) {
            return null;
        }

        return trim($payload) !== '' ? trim($payload) : null;
    }

    private function sanitizeCommandValue(string $value): string
    {
        return str_replace(["\r", "\n", "\t"], ' ', trim($value));
    }

    private function resolveDevicePin(Employee $employee): ?string
    {
        foreach ([$employee->sync_uid, $employee->uid, $employee->employee_id] as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate !== '') {
                return substr($candidate, 0, 32);
            }
        }

        return null;
    }

    private function applyLifecycleTimestamps(Employee $employee, array $attributes, string $status): array
    {
        if ($status === Employee::STATUS_SUSPENDED && ! $employee->suspended_at) {
            $attributes['suspended_at'] = now();
        }

        if (in_array($status, Employee::resignedStatuses(), true) && ! $employee->resigned_at) {
            $attributes['resigned_at'] = now();
        }

        return $attributes;
    }
}
