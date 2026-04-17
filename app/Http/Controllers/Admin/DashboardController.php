<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
