<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;

class DeviceController extends Controller
{
    const MAJOR = 1;

    const MINOR = 2;

    const PATCH = 3;

    public static function get()
    {
        $commitHash = trim(exec('git log --pretty="%h" -n1 HEAD'));

        $commitDate = new \DateTime(trim(exec('git log -n1 --pretty=%ci HEAD')));
        $commitDate->setTimezone(new \DateTimeZone('UTC'));

        return sprintf('v%s.%s.%s-dev.%s (%s)', self::MAJOR, self::MINOR, self::PATCH, $commitHash, $commitDate->format('Y-m-d H:i:s'));
    }

    public function index(Request $request)
    {
        $data['lable'] = 'Devices';
        $data['log'] = DB::table('devices')->select('id', 'no_sn', 'online')->orderBy('online', 'DESC')->get();

        return view('devices.index', $data);
    }

    public function DeviceLog(Request $request)
    {
        $data['lable'] = 'Devices Log';
        // Specify the number of items per page
        $perPage = 10;
        $data['log'] = DB::table('device_log')->select('id', 'data', 'url')->orderBy('id', 'DESC')->paginate($perPage);

        return view('devices.log', $data);
    }

    public function FingerLog(Request $request)
    {
        $data['lable'] = 'Finger Log';
        // Specify the number of items per page
        $perPage = 10;
        // Paginate the query
        $data['log'] = DB::table('finger_log')
            ->select('id', 'data', 'url')
            ->orderBy('id', 'DESC')
            ->paginate($perPage);

        return view('devices.log', $data);
    }

    public function MapId(Request $request)
    {
        return view('devices.map_id');
    }

    public function Attendance(Request $request)
    {
        return view('devices.attendance');
    }

    public function getAttendance(Request $request)
    {
        $query = DB::table('attendances')
            ->leftJoin('employees', 'attendances.employee_id', '=', 'employees.employee_id')
            ->select(
                'attendances.id',
                'attendances.sn',
                'attendances.employee_id',
                'attendances.timestamp',
                'attendances.status1',
                'attendances.status2',
                'employees.name as employee_name'
            )
            ->orderBy('attendances.timestamp', 'DESC');
        if ($request->filled('start_date')) {
            $query->whereDate('attendances.timestamp', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('attendances.timestamp', '<=', $request->end_date);
        }
        if ($request->filled('employee_id')) {
            $query->where('attendances.employee_id', '=', $request->employee_id);
        }
        if ($request->filled('employee_name')) {
            $query->where('employees.name', 'like', $request->employee_name.'%');
        }

        return DataTables::of($query)
            ->addIndexColumn()
            ->addColumn('employee_name', function ($row) {
                return $row->employee_name ?? '';
            })
            ->toJson();
    }

    public function daily(Request $request)
    {
        return view('devices.daily');
    }

    public function getDailyAttendanceSummary(Request $request)
    {
        // Determine the date to filter
        $start_date = $request->input('start_date');
        // Validate the date input
        if ($start_date && Carbon::canBeCreatedFromFormat($start_date, 'Y-m-d')) {
            $date = Carbon::createFromFormat('Y-m-d', $start_date);
        } else {
            $date = Carbon::yesterday(); // Default to yesterday if the date is invalid
        }

        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();

        // Query to get all employees
        $employees = DB::table('employees')->select('employee_id', 'name')->get();
        // Query to get attendance data for the specified date
        $attendanceData = DB::table('attendances')
            ->select(
                'employee_id',
                DB::raw('MIN(CASE WHEN status1 = 0 THEN timestamp END) as first_in'),
                DB::raw('MAX(CASE WHEN status1 = 1 THEN timestamp END) as last_out')
            )
            ->whereBetween('timestamp', [$startOfDay, $endOfDay])
            ->groupBy('employee_id')
            ->get()
            ->keyBy('employee_id');

        // Prepare the attendance summary
        $attendanceSummary = $employees->map(function ($employee) use ($attendanceData) {

            $attendance = $attendanceData->get($employee->employee_id);
            $firstIn = $attendance && $attendance->first_in ? Carbon::parse($attendance->first_in) : null;
            $lastOut = $attendance && $attendance->last_out ? Carbon::parse($attendance->last_out) : null;
            $totalTime = ($firstIn && $lastOut) ? sprintf('%02d:%02d', $firstIn->diffInHours($lastOut), $firstIn->diffInMinutes($lastOut) % 60) : 'N/A';

            return [
                'employee_id' => $employee->employee_id,
                'employee_name' => $employee->name ?: '',
                'first_in' => $firstIn ? $firstIn->format('d/m/Y, h:i A') : 'No Checkin', // Show N/A if first_in is null
                'last_out' => $lastOut ? $lastOut->format('d/m/Y, h:i A') : 'No Checkout', // Show N/A if last_out is null
                'total_time' => $totalTime,
            ];
        });

        return response()->json([
            'data' => $attendanceSummary,
            'startOfDay' => $startOfDay->toDateTimeString(),
            'endOfDay' => $endOfDay->toDateTimeString(),
        ]);
    }

    public function monthly(Request $request)
    {
        return view('devices.monthly');
    }

    public function getMonthlyAttendanceSummary(Request $request)
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $employeeId = $request->input('employee_id');
        $employeeName = $request->input('employee_name');
        // Validate input
        if (! $startDate || ! $endDate || (! $employeeId && ! $employeeName)) {
            return response()->json(['data' => []]); // Return empty array if required inputs are missing
        }
        // Parse dates
        $startDate = Carbon::parse($startDate)->startOfDay();
        $endDate = Carbon::parse($endDate)->endOfDay();
        // Find the employee
        $employee = DB::table('employees')
            ->where(function ($query) use ($employeeId, $employeeName) {
                if ($employeeId) {
                    $query->where('employee_id', $employeeId);
                }
                if ($employeeName) {
                    $query->orWhere('name', 'like', '%'.$employeeName.'%');
                }
            })
            ->first();
        // If employee not found, return empty array
        if (! $employee) {
            return response()->json(['data' => []]);
        }
        // Fetch attendance records for the employee
        $attendanceData = DB::table('attendances')
            ->select(
                DB::raw('DATE(timestamp) as date'),
                DB::raw('MIN(CASE WHEN status1 = 0 THEN timestamp END) as first_in'),
                DB::raw('MAX(CASE WHEN status1 = 1 THEN timestamp END) as last_out')
            )
            ->where('employee_id', $employee->employee_id)
            ->whereBetween('timestamp', [$startDate, $endDate])
            ->groupBy(DB::raw('DATE(timestamp)'))
            ->get();
        // Prepare the result
        $result = $attendanceData->map(function ($record) use ($employee) {
            $firstIn = $record->first_in ? Carbon::parse($record->first_in) : null;
            $lastOut = $record->last_out ? Carbon::parse($record->last_out) : null;
            $totalTime = ($firstIn && $lastOut) ? $firstIn->diff($lastOut)->format('%H:%I') : 'N/A';

            return [
                'employee_id' => $employee->employee_id,
                'employee_name' => $employee->name,
                'date' => $record->date,
                'first_in' => $firstIn ? $firstIn->format('h:i A') : '-',
                'last_out' => $lastOut ? $lastOut->format('h:i A') : '-',
                'total_hours' => $totalTime,
            ];
        });

        return response()->json(['data' => $result]);
    }
}
