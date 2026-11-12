<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Query\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Validation\Rule;

/**
 * Read-only browser for the ORIGINAL Att2000_V4 tables.
 *
 * No writes to SQL Server, no migrations and no queued ADMS/device commands.
 * Device communication and application auth use the isolated adms schema
 * inside Att2000_V4 (no MySQL or cloned source database).
 */
class LegacyAttendanceController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(config('legacy_attendance.browser_enabled', false), 404);

        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(['employees', 'attendance', 'devices', 'templates'])],
            'search' => ['nullable', 'string', 'max:80'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'badge' => ['nullable', 'string', 'max:80'],
            'name' => ['nullable', 'string', 'max:80'],
            'department' => ['nullable', 'string', 'max:80'],
            'device' => ['nullable', 'string', 'max:80'],
        ]);

        $tab = $filters['tab'] ?? 'employees';
        $search = trim((string) ($filters['search'] ?? ''));
        $source = DB::connection('legacy_sqlsrv');

        // The main Attendance(SQL) page uses the same familiar transaction
        // columns as BioCloud, linked directly to the ORIGINAL dbo tables.
        if ($tab === 'attendance') {
            return $this->attendancePage($filters, $search);
        }

        $totals = [
            'employees' => (int) $source->table('dbo.Employee')->count(),
            'attendance' => (int) $source->table('dbo.Attendance')->count(),
            'devices' => (int) $source->table('dbo.Device')->count(),
            'templates' => (int) $source->table('dbo.EmployeeTemplate')->count(),
        ];

        $rows = null;
        $templateStats = collect();

        switch ($tab) {
            case 'employees':
                $query = $source->table('dbo.Employee')->select([
                    'Id', 'BadgeNumber', 'NAME', 'DepartmentId', 'CardNo', 'HiredDay',
                ]);
                if ($search !== '') {
                    $query->where(function ($q) use ($search): void {
                        $q->where('BadgeNumber', 'like', '%'.$search.'%')
                            ->orWhere('NAME', 'like', '%'.$search.'%');
                    });
                }
                $rows = $query->orderBy('Id')->simplePaginate(30)->withQueryString();
                break;

            case 'attendance':
                $query = $source->table('dbo.Attendance')->select([
                    'Id', 'BadgeNumber', 'VerifyTime', 'VerifyType', 'Status', 'DeviceSerialNumber',
                ]);
                if ($search !== '') {
                    $query->where(function ($q) use ($search): void {
                        $q->where('BadgeNumber', 'like', '%'.$search.'%')
                            ->orWhere('DeviceSerialNumber', 'like', '%'.$search.'%');
                    });
                }
                if (! empty($filters['date_from'])) {
                    $query->where('VerifyTime', '>=', CarbonImmutable::parse($filters['date_from'])->startOfDay());
                }
                if (! empty($filters['date_to'])) {
                    $query->where('VerifyTime', '<', CarbonImmutable::parse($filters['date_to'])->addDay()->startOfDay());
                }
                $rows = $query->orderByDesc('VerifyTime')->orderByDesc('Id')
                    ->simplePaginate(50)->withQueryString();
                break;

            case 'devices':
                $query = $source->table('dbo.Device')->select([
                    'Id', 'DeviceSerialNumber', 'DeviceAliasName', 'DeviceName', 'DeviceStatus',
                    'DeviceIP', 'push_protocol', 'fw_ver',
                ]);
                if ($search !== '') {
                    $query->where(function ($q) use ($search): void {
                        $q->where('DeviceSerialNumber', 'like', '%'.$search.'%')
                            ->orWhere('DeviceAliasName', 'like', '%'.$search.'%');
                    });
                }
                $rows = $query->orderBy('Id')->simplePaginate(30)->withQueryString();
                break;

            case 'templates':
                // Biometric template payloads are never selected or rendered.
                $templateStats = $source->table('dbo.EmployeeTemplate')
                    ->select(['TemplateType', 'Format'])
                    ->selectRaw('COUNT_BIG(*) AS total')
                    ->groupBy('TemplateType', 'Format')
                    ->orderBy('TemplateType')
                    ->get();

                $query = $source->table('dbo.EmployeeTemplate')->select([
                    'Id', 'BadgeNumber', 'TemplateType', 'TemplateIndex', 'TemplateNo',
                    'Format', 'MajorVer', 'MinorVer',
                ]);
                if ($search !== '') {
                    $query->where('BadgeNumber', 'like', '%'.$search.'%');
                }
                $rows = $query->orderBy('Id')->simplePaginate(30)->withQueryString();
                break;
        }

        return view('admin.legacy.index', compact(
            'tab', 'filters', 'search', 'totals', 'rows', 'templateStats',
        ));
    }

    private function attendancePage(array $filters, string $search): View
    {
        $source = DB::connection('legacy_sqlsrv');
        $filters['date_from'] = $filters['date_from'] ?? '2026-11-04';
        $filters['date_to'] = $filters['date_to'] ?? now('Asia/Dubai')->toDateString();

        $departments = $this->departmentNames();
        $departmentIds = $source->table('dbo.Employee')
            ->whereNotNull('DepartmentId')->select('DepartmentId')
            ->distinct()->orderBy('DepartmentId')->pluck('DepartmentId');

        $devices = $source->table('dbo.Device')
            ->select(['DeviceSerialNumber', 'DeviceAliasName', 'DeviceName'])
            ->orderBy('DeviceAliasName')->get();

        $rows = $this->attendanceQuery($filters, $search)
            ->orderByDesc('a.VerifyTime')->orderByDesc('a.Id')
            ->simplePaginate(50)->withQueryString();

        return view('admin.legacy.attendance', compact(
            'rows', 'filters', 'search', 'departments', 'departmentIds', 'devices'
        ));
    }

    /**
     * Original records only. No adms sample user names or manual branch mapping.
     * Matching uses exact BadgeNumber and DeviceSerialNumber.
     */
    private function attendanceQuery(array $filters, string $search): Builder
    {
        $source = DB::connection('legacy_sqlsrv');
        // Canonicalize keys BEFORE joining: duplicate legacy registrations
        // must never multiply attendance rows or break pagination/export.
        $employeeKeys = $source->table('dbo.Employee')
            ->select('BadgeNumber')->selectRaw('MIN(Id) as CanonicalId')
            ->groupBy('BadgeNumber');
        $deviceKeys = $source->table('dbo.Device')
            ->select('DeviceSerialNumber')->selectRaw('MIN(Id) as CanonicalId')
            ->groupBy('DeviceSerialNumber');

        $query = $source->table('dbo.Attendance as a')
            ->leftJoinSub($employeeKeys, 'em', 'em.BadgeNumber', '=', 'a.BadgeNumber')
            ->leftJoin('dbo.Employee as e', 'e.Id', '=', 'em.CanonicalId')
            ->leftJoinSub($deviceKeys, 'dm', 'dm.DeviceSerialNumber', '=', 'a.DeviceSerialNumber')
            ->leftJoin('dbo.Device as d', 'd.Id', '=', 'dm.CanonicalId')
            ->select([
                'a.Id', 'a.BadgeNumber', 'a.VerifyTime', 'a.Status', 'a.VerifyType',
                'a.DeviceSerialNumber', 'a.Remark', 'e.NAME as EmployeeName',
                'e.DepartmentId as DepartmentId',
                'd.DeviceAliasName as DeviceAliasName', 'd.DeviceName as DeviceModelName',
            ]);

        $badge = trim((string) ($filters['badge'] ?? ''));
        $name = trim((string) ($filters['name'] ?? ''));
        $dept = trim((string) ($filters['department'] ?? ''));
        $device = trim((string) ($filters['device'] ?? ''));

        if ($badge !== '') {
            $query->where('a.BadgeNumber', 'like', '%'.$badge.'%');
        }
        if ($name !== '') {
            $query->where('e.NAME', 'like', '%'.$name.'%');
        }
        if ($dept !== '') {
            $query->where('e.DepartmentId', $dept);
        }
        if ($device !== '') {
            $query->where('a.DeviceSerialNumber', $device);
        }
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('a.BadgeNumber', 'like', '%'.$search.'%')
                    ->orWhere('a.DeviceSerialNumber', 'like', '%'.$search.'%')
                    ->orWhere('e.NAME', 'like', '%'.$search.'%');
            });
        }
        if (! empty($filters['date_from'])) {
            $query->where('a.VerifyTime', '>=', $filters['date_from'].' 00:00:00');
        }
        if (! empty($filters['date_to'])) {
            $end = CarbonImmutable::parse($filters['date_to'], 'Asia/Dubai')
                ->addDay()->toDateString().' 00:00:00';
            $query->where('a.VerifyTime', '<', $end);
        }

        return $query;
    }

    /**
     * The legacy schema version may call the source table Department or
     * Departments and its label NAME or DepartmentName. Detect names from
     * information_schema rather than guessing, never querying templates.
     */
    private function departmentNames(): array
    {
        $source = DB::connection('legacy_sqlsrv');
        try {
            $metadata = $source->select(
                "SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME IN ('Department', 'Departments')"
            );
        } catch (\Throwable) {
            // Some SQL reader accounts were originally granted SELECT only
            // on Employee/Attendance/Device/EmployeeTemplate.
            return [];
        }
        foreach (['Department', 'Departments'] as $table) {
            $names = [];
            foreach ($metadata as $col) {
                if (strcasecmp((string) $col->TABLE_NAME, $table) === 0) {
                    $names[] = (string) $col->COLUMN_NAME;
                }
            }
            $idCol = collect(['Id', 'DepartmentId'])->first(
                fn ($v) => in_array(strtolower($v), array_map('strtolower', $names), true)
            );
            $nameCol = collect(['DepartmentName', 'NAME', 'Name', 'DeptName'])->first(
                fn ($v) => in_array(strtolower($v), array_map('strtolower', $names), true)
            );
            if ($idCol && $nameCol) {
                try {
                    return $source->table('dbo.'.$table)->select([$idCol, $nameCol])
                        ->get()->mapWithKeys(static fn ($d) => [
                            (string) $d->{$idCol} => (string) $d->{$nameCol}
                        ])->all();
                } catch (\Throwable) {
                    return [];
                }
            }
        }
        return [];
    }

    public function exportAttendance(Request $request): StreamedResponse
    {
        abort_unless(config('legacy_attendance.browser_enabled', false), 404);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'badge' => ['nullable', 'string', 'max:80'],
            'name' => ['nullable', 'string', 'max:80'],
            'department' => ['nullable', 'string', 'max:80'],
            'device' => ['nullable', 'string', 'max:80'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $start = CarbonImmutable::parse($filters['date_from'], 'Asia/Dubai');
        $end = CarbonImmutable::parse($filters['date_to'], 'Asia/Dubai');
        abort_if($start->diffInDays($end) > 31, 422, 'CSV export date range must not exceed 31 days.');

        $departments = $this->departmentNames();
        $query = $this->attendanceQuery($filters, trim((string) ($filters['search'] ?? '')))
            ->orderByDesc('a.VerifyTime')->orderByDesc('a.Id')->limit(50000);

        return response()->streamDownload(static function () use ($query, $departments): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Employee ID', 'Name', 'Department Name', 'Device Name',
                'Punch Date', 'Status', 'Verify Type', 'Device Serial', 'Remark',
            ]);
            foreach ($query->cursor() as $row) {
                fputcsv($handle, [
                    $row->BadgeNumber,
                    $row->EmployeeName,
                    $departments[(string) $row->DepartmentId] ?? $row->DepartmentId,
                    $row->DeviceAliasName ?: $row->DeviceModelName,
                    $row->VerifyTime,
                    $row->Status,
                    $row->VerifyType,
                    $row->DeviceSerialNumber,
                    $row->Remark,
                ]);
            }
            fclose($handle);
        }, 'superfix-attendance-'.$filters['date_from'].'-to-'.$filters['date_to'].'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
