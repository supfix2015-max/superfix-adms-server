<?php

namespace App\Repositories;

use App\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class EmployeeRepository extends BaseRepository
{
    public function __construct(Employee $model)
    {
        parent::__construct($model);
    }

    public function findByEmployeeId(string $employeeId): ?Employee
    {
        return $this->model->where('employee_id', $employeeId)->first();
    }

    public function findByEmail(string $email): ?Employee
    {
        return $this->model->where('email', $email)->first();
    }

    public function getActive(): Collection
    {
        return $this->model->active()->get();
    }

    public function paginateWithFilters(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->query();

        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['department'])) {
            $query->where('department', $filters['department']);
        }

        if (! empty($filters['date_from'])) {
            $query->where('hire_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('hire_date', '<=', $filters['date_to']);
        }

        return $query->latest('created_at')->paginate($perPage);
    }

    public function addBiometricId(int $employeeId, int $deviceId, int $biometricId): bool
    {
        $employee = $this->find($employeeId);
        if (! $employee) {
            return false;
        }

        $biometricIds = $employee->biometric_ids ?? [];
        if (! isset($biometricIds[$deviceId])) {
            $biometricIds[$deviceId] = [];
        }

        if (! in_array($biometricId, $biometricIds[$deviceId])) {
            $biometricIds[$deviceId][] = $biometricId;
        }

        return $employee->update(['biometric_ids' => $biometricIds]);
    }

    public function getBiometricId(int $employeeId, int $deviceId): array
    {
        $employee = $this->find($employeeId);
        if (! $employee || ! $employee->biometric_ids) {
            return [];
        }

        return $employee->biometric_ids[$deviceId] ?? [];
    }

    public function getDepartments(): Collection
    {
        return $this->model->select('department')
            ->distinct()
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->get(['department']);
    }

    public function getStatistics(): array
    {
        return [
            'total' => $this->count(),
            'active' => $this->model->where('status', 'active')->count(),
            'inactive' => $this->model->where('status', 'inactive')->count(),
            'suspended' => $this->model->whereIn('status', ['suspended', 'on_leave'])->count(),
        ];
    }
}
