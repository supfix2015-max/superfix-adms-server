<?php

namespace App\Services;

use App\Models\Employee;
use App\Repositories\EmployeeRepository;

class EmployeeService
{
    public function __construct(protected EmployeeRepository $repository) {}

    public function createEmployee(array $data): Employee
    {
        return $this->repository->create($data);
    }

    public function updateEmployee(int $employeeId, array $data): bool
    {
        return $this->repository->update($employeeId, $data);
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
        return $this->repository->update($employeeId, ['status' => 'active']);
    }

    public function deactivateEmployee(int $employeeId): bool
    {
        return $this->repository->update($employeeId, ['status' => 'inactive']);
    }

    public function suspendEmployee(int $employeeId): bool
    {
        return $this->repository->update($employeeId, ['status' => 'suspended']);
    }

    public function getDepartments()
    {
        return $this->repository->getDepartments();
    }

    public function getEmployeeStatistics(): array
    {
        return $this->repository->getStatistics();
    }

    public function deleteEmployee(int $employeeId): bool
    {
        return $this->repository->delete($employeeId);
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
}
