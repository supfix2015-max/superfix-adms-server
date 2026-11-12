<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LegacyAttendanceReportController extends Controller
{
    private const REPORTS = [
        'first-last' => 'First & Last',
        'first-in-last-out' => 'First in last out',
        'multi-in-out' => 'Multi In Out',
        'daily' => 'Daily Attendance',
        'monthly' => 'Monthly Attendance',
        'summary' => 'Attendance Summary',
    ];

    public function show(Request $request, string $report): View
    {
        abort_unless(config('legacy_attendance.browser_enabled', false), 404);
        abort_unless(isset(self::REPORTS[$report]), 404);
        $filters = $request->validate([
            'badge' => ['nullable', 'string', 'max:80'],
            'department' => ['nullable', 'string', 'max:80'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $today = CarbonImmutable::now('Asia/Dubai');
        $filters['date_from'] = $filters['date_from']
            ?? ($report === 'monthly' ? $today->startOfMonth()->toDateString()
                : $today->subDays(6)->toDateString());
        $filters['date_to'] = $filters['date_to'] ?? $today->toDateString();

        $from = CarbonImmutable::parse($filters['date_from'], 'Asia/Dubai');
        $to = CarbonImmutable::parse($filters['date_to'], 'Asia/Dubai');
        abort_if($from->gt($to), 422, 'From date must be before or equal to To date.');
        abort_if($from->diffInDays($to) > 92, 422, 'Maximum report range is 93 days.');

        $db = DB::connection('legacy_sqlsrv');
        $dateExpr = match ($report) {
            'monthly' => "CONVERT(varchar(7), a.VerifyTime, 120)",
            'summary' => "'".'Selected range'."'",
            default => 'CONVERT(varchar(10), a.VerifyTime, 120)',
        };

        $query = $db->table('dbo.Attendance as a')
            ->where('a.VerifyTime', '>=', $filters['date_from'].' 00:00:00')
            ->where('a.VerifyTime', '<', $to->addDay()->toDateString().' 00:00:00')
            ->whereNotNull('a.BadgeNumber')
            ->select('a.BadgeNumber')
            ->selectRaw("{$dateExpr} AS WorkPeriod")
            ->selectRaw('MIN(a.VerifyTime) AS FirstPunch')
            ->selectRaw('MAX(a.VerifyTime) AS LastPunch')
            ->selectRaw('COUNT_BIG(*) AS TotalPunches')
            ->selectRaw("SUM(CASE WHEN a.Status = 'Check-In' THEN 1 ELSE 0 END) AS CheckIns")
            ->selectRaw("SUM(CASE WHEN a.Status = 'Check-Out' THEN 1 ELSE 0 END) AS CheckOuts")
            ->selectRaw("MIN(CASE WHEN a.Status = 'Check-In' THEN a.VerifyTime END) AS FirstIn")
            ->selectRaw("MAX(CASE WHEN a.Status = 'Check-Out' THEN a.VerifyTime END) AS LastOut")
            ->groupBy('a.BadgeNumber');

        if ($report !== 'summary') {
            $query->groupByRaw($dateExpr);
        }
        if (($filters['badge'] ?? '') !== '') {
            $query->where('a.BadgeNumber', 'like', '%'.$filters['badge'].'%');
        }
        if (($filters['department'] ?? '') !== '') {
            $query->whereIn('a.BadgeNumber', $db->table('dbo.Employee')
                ->where('DepartmentId', $filters['department'])
                ->select('BadgeNumber'));
        }

        $rows = $query->orderByRaw($dateExpr.' DESC')
            ->orderBy('a.BadgeNumber')
            ->simplePaginate(50)->withQueryString();

        $ids = $rows->getCollection()->pluck('BadgeNumber')->filter()->unique()->values()->all();
        $employees = $ids === [] ? collect() : $db->table('dbo.Employee')
            ->whereIn('BadgeNumber', $ids)
            ->get(['BadgeNumber', 'NAME', 'DepartmentId'])
            ->unique('BadgeNumber')->keyBy('BadgeNumber');

        $departments = $db->table('dbo.Employee')
            ->select('DepartmentId')->whereNotNull('DepartmentId')
            ->distinct()->orderBy('DepartmentId')->pluck('DepartmentId');

        return view('admin.legacy.report', [
            'report' => $report,
            'reportName' => self::REPORTS[$report],
            'rows' => $rows,
            'employees' => $employees,
            'filters' => $filters,
            'departments' => $departments,
        ]);
    }
}
