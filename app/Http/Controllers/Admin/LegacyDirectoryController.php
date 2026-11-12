<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LegacyDirectoryController extends Controller
{
    // Read-only views of original BioCloud-style directory tables, not
    // operator write endpoints. Never select passwords/biometric payloads.
    private const SECTIONS = [
        'employee' => ['Employee'],
        'department' => ['Department', 'Departments'],
        'area' => ['Area', 'Areas'],
        'position' => ['Position', 'Positions'],
        'holiday' => ['Holiday', 'Holidays'],
        'timetable' => ['Timetable', 'TimeTable'],
        'shift' => ['Shift', 'Shifts'],
        'department-schedule' => ['DepartmentSchedule', 'Department_Schedule'],
        'employee-schedule' => ['EmployeeSchedule', 'Employee_Schedule'],
        'temporary-schedule' => ['TemporarySchedule', 'Temporary_Schedule'],
        'employee-leave' => ['EmployeeLeave', 'Leave', 'EmployeeLeaveInfo'],
    ];

    private const ALLOWED_COLUMNS = [
        'Id', 'BadgeNumber', 'NAME', 'Name', 'DepartmentId', 'DepartmentName',
        'AreaId', 'AreaName', 'PositionId', 'PositionName', 'Gender',
        'CardNo', 'PassportNo', 'Phone', 'PhoneNumber', 'HiredDay', 'HireDate',
        'HolidayName', 'HolidayDate', 'TimetableName', 'ShiftName',
        'StartTime', 'EndTime', 'StartDate', 'EndDate', 'Status',
        'Remarks', 'Remark', 'LeaveType', 'LeaveDate', 'BeginDate', 'FinishDate',
        'ScheduleName', 'Description',
    ];

    public function index(Request $request, string $section): View
    {
        abort_unless(config('legacy_attendance.browser_enabled', false), 404);
        abort_unless(isset(self::SECTIONS[$section]), 404);

        $filters = $request->validate([
            'id' => ['nullable', 'string', 'max:80'],
            'name' => ['nullable', 'string', 'max:80'],
            'department' => ['nullable', 'string', 'max:80'],
        ]);

        $db = DB::connection('legacy_sqlsrv');
        $names = self::SECTIONS[$section];
        $schema = $db->select(
            "SELECT TABLE_NAME, COLUMN_NAME
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = 'dbo'
                AND TABLE_NAME IN (".implode(',', array_fill(0, count($names), '?')).")",
            $names
        );

        $byTable = [];
        foreach ($schema as $c) {
            $byTable[(string) $c->TABLE_NAME][] = (string) $c->COLUMN_NAME;
        }
        $table = null;
        foreach ($names as $name) {
            if (isset($byTable[$name])) {
                $table = $name;
                break;
            }
        }

        $rows = null;
        $columns = [];
        $notice = '';
        $departmentNames = [];
        $departments = collect();

        if ($section === 'employee') {
            // Basic Employee SELECT is already permitted for the readonly
            // legacy reader, even when Department/Area tables are restricted.
            $departments = $db->table('dbo.Employee')
                ->select('DepartmentId')->whereNotNull('DepartmentId')
                ->distinct()->orderBy('DepartmentId')->pluck('DepartmentId');
            $departmentNames = $this->lookupDepartments($db);
        }

        if ($table !== null) {
            $found = $byTable[$table];
            $columns = array_values(array_filter(self::ALLOWED_COLUMNS,
                static fn (string $col): bool => in_array($col, $found, true)
            ));
            // Lowercase comparisons support SQL metadata with variant casing.
            if ($columns === [] && count($found) !== 0) {
                $columns = array_values(array_filter($found, static fn (string $col): bool =>
                    in_array(strtolower($col), array_map('strtolower', self::ALLOWED_COLUMNS), true)
                ));
            }

            if ($columns === []) {
                $notice = 'The source table exists, but no safe display columns were recognized.';
            } else {
                try {
                    $q = $db->table('dbo.'.$table)->select($columns);
                    $id = $this->column($columns, ['BadgeNumber', 'Id', 'DepartmentId', 'AreaId']);
                    $name = $this->column($columns, [
                        'NAME', 'Name', 'DepartmentName', 'AreaName',
                        'PositionName', 'HolidayName', 'ShiftName',
                    ]);
                    $department = $this->column($columns, ['DepartmentId']);
                    if ($id && ($filters['id'] ?? '') !== '') {
                        $q->where($id, 'like', '%'.$filters['id'].'%');
                    }
                    if ($name && ($filters['name'] ?? '') !== '') {
                        $q->where($name, 'like', '%'.$filters['name'].'%');
                    }
                    if ($department && ($filters['department'] ?? '') !== '') {
                        $q->where($department, $filters['department']);
                    }
                    $rows = $q->orderBy($id ?: ($name ?: $columns[0]))
                        ->simplePaginate(30)->withQueryString();
                } catch (\Throwable) {
                    $notice = 'This table is not readable with the existing restricted SQL login. Grant SELECT on that table only, if required.';
                }
            }
        } else {
            $notice = 'No matching original dbo table is visible to the restricted SQL reader. This section is not yet available.';
        }

        return view('admin.legacy.directory', compact(
            'section', 'filters', 'rows', 'columns', 'notice', 'departments', 'departmentNames', 'table'
        ));
    }

    private function column(array $columns, array $desired): ?string
    {
        foreach ($desired as $key) {
            foreach ($columns as $col) {
                if (strcasecmp($col, $key) === 0) {
                    return $col;
                }
            }
        }
        return null;
    }

    private function lookupDepartments($db): array
    {
        try {
            foreach (['Department', 'Departments'] as $table) {
                $meta = $db->select(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME = ?", [$table]
                );
                $cols = array_map(static fn ($c): string => (string) $c->COLUMN_NAME, $meta);
                $id = $this->column($cols, ['Id', 'DepartmentId']);
                $name = $this->column($cols, ['DepartmentName', 'NAME', 'Name']);
                if ($id && $name) {
                    return $db->table('dbo.'.$table)->get([$id, $name])
                        ->mapWithKeys(static fn ($v) => [
                            (string) $v->{$id} => (string) $v->{$name},
                        ])->all();
                }
            }
        } catch (\Throwable) {
            return [];
        }
        return [];
    }
}
