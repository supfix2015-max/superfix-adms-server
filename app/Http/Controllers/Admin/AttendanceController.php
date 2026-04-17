<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Employee;
use App\Repositories\AttendanceLogRepository;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function __construct(protected AttendanceLogRepository $repository) {}

    public function index(Request $request)
    {
        $filters = [
            'device_id' => $request->input('device_id'),
            'employee_id' => $request->input('employee_id'),
            'log_type' => $request->input('log_type'),
            'status' => $request->input('status'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ];

        $logs = $this->repository->paginateWithFilters($filters, 50);
        $devices = Device::all();
        $employees = Employee::all();

        return view('admin.attendance.index', [
            'logs' => $logs,
            'filters' => $filters,
            'devices' => $devices,
            'employees' => $employees,
        ]);
    }

    public function export(Request $request)
    {
        $filters = [
            'device_id' => $request->input('device_id'),
            'employee_id' => $request->input('employee_id'),
            'log_type' => $request->input('log_type'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ];

        $logs = $this->repository->getModel()
            ->with(['device', 'employee'])
            ->when(! empty($filters), fn ($q) => $this->applyFilters($q, $filters))
            ->orderByDesc('log_time')
            ->get();

        // Could use maatwebsite/excel here
        // For now, just return CSV
        return response()->streamDownload(function () use ($logs) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Device', 'Employee', 'Type', 'Verify Type', 'Time', 'Status']);

            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->device?->name ?? 'N/A',
                    $log->employee?->full_name ?? $log->employee_code,
                    $log->log_type,
                    $log->verify_type,
                    $log->log_time->format('Y-m-d H:i:s'),
                    $log->status,
                ]);
            }

            fclose($handle);
        }, 'attendance_'.now()->format('Y-m-d').'.csv');
    }

    private function applyFilters($query, array $filters)
    {
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

        return $query;
    }
}
