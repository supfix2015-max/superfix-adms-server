<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Read-only browser for the ORIGINAL Att2000_V4 tables.
 *
 * No writes to SQL Server, no migrations and no queued ADMS/device commands.
 * Device communication and application auth continue to use MySQL.
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
        ]);

        $tab = $filters['tab'] ?? 'employees';
        $search = trim((string) ($filters['search'] ?? ''));
        $source = DB::connection('legacy_sqlsrv');

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
}
