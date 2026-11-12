<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\LegacyDashboardService;
use App\Repositories\AttendanceLogRepository;
use App\Repositories\DeviceRepository;
use App\Repositories\EmployeeRepository;

class DashboardController extends Controller
{
    public function __construct(
        protected DeviceRepository $deviceRepo,
        protected AttendanceLogRepository $attendanceRepo,
        protected EmployeeRepository $employeeRepo
    ) {}

    public function index()
    {
        if (config('legacy_attendance.browser_enabled', false)) {
            return view('admin.dashboard_sql', app(LegacyDashboardService::class)->overview());
        }

        $deviceStats = $this->deviceRepo->getStatistics();
        $attendanceStats = $this->attendanceRepo->getStatistics();
        $employeeStats = $this->employeeRepo->getStatistics();

        $recentDevices = $this->deviceRepo->recentDevices();
        $recentLogs = $this->attendanceRepo->recentLogs();
        $unmatched = $this->attendanceRepo->getUnmatchedLogs()->count();

        return view('admin.dashboard', compact(
            'deviceStats',
            'attendanceStats',
            'employeeStats',
            'recentDevices',
            'recentLogs',
            'unmatched'
        ));
    }
}
