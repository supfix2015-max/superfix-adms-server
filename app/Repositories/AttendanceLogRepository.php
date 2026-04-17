<?php

namespace App\Repositories;

use App\Models\AttendanceLog;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class AttendanceLogRepository extends BaseRepository
{
    public function __construct(AttendanceLog $model)
    {
        parent::__construct($model);
    }

    public function recentLogs(int $limit = 10)
    {
        return $this->model
            ->latest('log_time')
            ->limit($limit)
            ->get();
    }

    public function paginateWithFilters(array $filters, int $perPage = 50): LengthAwarePaginator
    {
        $query = $this->model->with(['device', 'employee']);

        if (! empty($filters['device_id'])) {
            $query->where('device_id', $filters['device_id']);
        }

        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }

        if (! empty($filters['log_type'])) {
            $query->where('log_type', $filters['log_type']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('log_time', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('log_time', '<=', $filters['date_to']);
        }

        return $query->latest('log_time')->paginate($perPage);
    }

    public function getByDeviceAndDate(int $deviceId, Carbon $date): Collection
    {
        return $this->model
            ->where('device_id', $deviceId)
            ->whereDate('log_time', $date)
            ->latest('log_time')
            ->get();
    }

    public function getByEmployeeAndDateRange(int $employeeId, Carbon $startDate, Carbon $endDate): Collection
    {
        return $this->model
            ->where('employee_id', $employeeId)
            ->whereBetween('log_time', [$startDate, $endDate])
            ->latest('log_time')
            ->get();
    }

    public function getPendingLogs(): Collection
    {
        return $this->model->pending()->latest('created_at')->get();
    }

    public function getErrorLogs(): Collection
    {
        return $this->model->where('status', 'error')->latest('created_at')->limit(100)->get();
    }

    public function markAsProcessed(int $logId): bool
    {
        return $this->update($logId, ['status' => 'processed']);
    }

    public function markAsError(int $logId, string $errorMessage): bool
    {
        return $this->update($logId, [
            'status' => 'error',
            'error_message' => $errorMessage,
        ]);
    }

    public function getStatistics(): array
    {
        $total = $this->count();
        $processed = $this->model->where('status', 'processed')->count();
        $pending = $this->model->where('status', 'pending')->count();
        $errors = $this->model->where('status', 'error')->count();

        return [
            'total' => $total,
            'processed' => $processed,
            'pending' => $pending,
            'errors' => $errors,
            'by_type' => $this->model->groupBy('log_type')->selectRaw('log_type, count(*) as count')->pluck('count', 'log_type'),
        ];
    }

    public function getDailyStatistics(Carbon $date): array
    {
        return $this->model
            ->whereDate('log_time', $date)
            ->groupBy('log_type')
            ->selectRaw('log_type, count(*) as count')
            ->pluck('count', 'log_type')
            ->toArray();
    }

    public function getUnmatchedLogs(): Collection
    {
        return $this->model->whereNull('employee_id')->latest('log_time')->limit(100)->get();
    }
}
