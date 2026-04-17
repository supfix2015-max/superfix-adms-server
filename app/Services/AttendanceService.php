<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Repositories\AttendanceLogRepository;
use Carbon\Carbon;

class AttendanceService
{
    public function __construct(protected AttendanceLogRepository $repository) {}

    public function createLog(array $data): AttendanceLog
    {
        $data['status'] = $data['status'] ?? 'pending';
        $data['log_type'] = $data['log_type'] ?? 'unknown';
        $data['verify_type'] = $data['verify_type'] ?? 'unknown';

        return $this->repository->create($data);
    }

    public function getLog(int $logId): ?AttendanceLog
    {
        return $this->repository->find($logId);
    }

    public function markAsProcessed(int $logId): bool
    {
        return $this->repository->markAsProcessed($logId);
    }

    public function markAsError(int $logId, string $errorMessage): bool
    {
        return $this->repository->markAsError($logId, $errorMessage);
    }

    public function getPendingLogs()
    {
        return $this->repository->getPendingLogs();
    }

    public function getErrorLogs()
    {
        return $this->repository->getErrorLogs();
    }

    public function getEmployeeAttendance(int $employeeId, Carbon $startDate, Carbon $endDate)
    {
        return $this->repository->getByEmployeeAndDateRange($employeeId, $startDate, $endDate);
    }

    public function getDeviceLogs(int $deviceId, Carbon $date)
    {
        return $this->repository->getByDeviceAndDate($deviceId, $date);
    }

    public function getUnmatchedLogs()
    {
        return $this->repository->getUnmatchedLogs();
    }

    public function matchLogToEmployee(int $logId, int $employeeId): bool
    {
        return $this->repository->update($logId, ['employee_id' => $employeeId]);
    }

    public function getStatistics(): array
    {
        return $this->repository->getStatistics();
    }

    public function getDailyStatistics(Carbon $date): array
    {
        return $this->repository->getDailyStatistics($date);
    }

    public function getCheckInCount(int $employeeId, Carbon $date): int
    {
        return $this->repository->getModel()
            ->where('employee_id', $employeeId)
            ->where('log_type', 'checkin')
            ->whereDate('log_time', $date)
            ->count();
    }

    public function getCheckOutCount(int $employeeId, Carbon $date): int
    {
        return $this->repository->getModel()
            ->where('employee_id', $employeeId)
            ->where('log_type', 'checkout')
            ->whereDate('log_time', $date)
            ->count();
    }

    public function getTodayAttendance(int $employeeId)
    {
        $today = now()->startOfDay();

        return $this->repository->getModel()
            ->where('employee_id', $employeeId)
            ->where('log_time', '>=', $today)
            ->orderBy('log_time')
            ->get();
    }

    public function getAttendanceReport(array $filters = [])
    {
        $filters['date_from'] = $filters['date_from'] ?? now()->subMonth();
        $filters['date_to'] = $filters['date_to'] ?? now();

        return $this->repository->paginateWithFilters($filters, 100);
    }
}
