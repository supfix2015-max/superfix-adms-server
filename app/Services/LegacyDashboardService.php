<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Original Att2000_V4 management dashboard.
 *
 * Reads only the source SQL Server database. Does not depend on copied
 * operational MySQL employees/attendance and never fetches biometric payloads.
 */
class LegacyDashboardService
{
    public function overview(): array
    {
        // Explicit file cache: do not use a MySQL cache backend on this screen.
        return Cache::store('file')->remember('legacy_sql_dashboard_v1', 300, function (): array {
            $db = DB::connection('legacy_sqlsrv');
            $zone = (string) (config('legacy_attendance.attendance_wallclock_timezone') ?: 'Asia/Dubai');

            $totals = [
                'employees' => (int) $db->table('dbo.Employee')->count(),
                'attendance' => (int) $db->table('dbo.Attendance')->count(),
                'devices' => (int) $db->table('dbo.Device')->count(),
                'templates' => (int) $db->table('dbo.EmployeeTemplate')->count(),
            ];

            $latestRaw = $db->table('dbo.Attendance')->whereNotNull('VerifyTime')->max('VerifyTime');
            $latestAt = $latestRaw ? CarbonImmutable::parse($latestRaw, $zone) : null;

            $lastDay = $latestAt?->startOfDay();
            $daily = [
                'total' => 0,
                'checkins' => 0,
                'checkouts' => 0,
                'unique_employees' => 0,
            ];
            $trend = [];
            $statusBreakdown = [];

            if ($lastDay !== null) {
                $dayStats = $db->table('dbo.Attendance')
                    ->where('VerifyTime', '>=', $lastDay->format('Y-m-d H:i:s'))
                    ->where('VerifyTime', '<', $lastDay->addDay()->format('Y-m-d H:i:s'))
                    ->selectRaw("COUNT_BIG(*) AS total,
                        COALESCE(SUM(CASE WHEN [Status] = 'Check-In' THEN 1 ELSE 0 END), 0) AS checkins,
                        COALESCE(SUM(CASE WHEN [Status] = 'Check-Out' THEN 1 ELSE 0 END), 0) AS checkouts,
                        COUNT(DISTINCT [BadgeNumber]) AS unique_employees")
                    ->first();

                $daily = [
                    'total' => (int) ($dayStats->total ?? 0),
                    'checkins' => (int) ($dayStats->checkins ?? 0),
                    'checkouts' => (int) ($dayStats->checkouts ?? 0),
                    'unique_employees' => (int) ($dayStats->unique_employees ?? 0),
                ];

                $from = $lastDay->subDays(6);
                $trendRows = $db->table('dbo.Attendance')
                    ->where('VerifyTime', '>=', $from->format('Y-m-d H:i:s'))
                    ->where('VerifyTime', '<', $lastDay->addDay()->format('Y-m-d H:i:s'))
                    ->selectRaw("CONVERT(varchar(10), [VerifyTime], 23) AS PunchDate, COUNT_BIG(*) AS Total")
                    ->groupByRaw("CONVERT(varchar(10), [VerifyTime], 23)")
                    ->get()
                    ->keyBy('PunchDate');

                for ($offset = 0; $offset < 7; $offset++) {
                    $day = $from->addDays($offset);
                    $date = $day->format('Y-m-d');
                    $trend[] = [
                        'date' => $date,
                        'day' => $day->format('D'),
                        'count' => (int) ($trendRows->get($date)?->Total ?? 0),
                    ];
                }

                $statusBreakdown = $db->table('dbo.Attendance')
                    ->where('VerifyTime', '>=', $lastDay->format('Y-m-d H:i:s'))
                    ->where('VerifyTime', '<', $lastDay->addDay()->format('Y-m-d H:i:s'))
                    ->select(['Status'])
                    ->selectRaw('COUNT_BIG(*) AS Total')
                    ->groupBy('Status')
                    ->orderByDesc('Total')
                    ->limit(6)
                    ->get()
                    ->map(static fn ($row): array => [
                        'label' => $row->Status ?: 'Not classified',
                        'count' => (int) $row->Total,
                    ])
                    ->all();
            }

            $recentPunches = $db->table('dbo.Attendance')
                ->select(['Id', 'BadgeNumber', 'VerifyTime', 'VerifyType', 'Status', 'DeviceSerialNumber'])
                ->whereNotNull('VerifyTime')
                ->orderByDesc('VerifyTime')
                ->orderByDesc('Id')
                ->limit(12)
                ->get();

            $registeredDevices = $db->table('dbo.Device')
                ->select(['Id', 'DeviceSerialNumber', 'DeviceAliasName', 'DeviceName', 'DeviceStatus'])
                ->orderByDesc('Id')
                ->limit(8)
                ->get();

            return [
                'totals' => $totals,
                'latestAt' => $latestAt,
                'daily' => $daily,
                'trend' => $trend,
                'statusBreakdown' => $statusBreakdown,
                'recentPunches' => $recentPunches,
                'registeredDevices' => $registeredDevices,
                'lastDataIsStale' => $latestAt === null || $latestAt->lessThan(CarbonImmutable::now($zone)->subDay()),
                'sourceTimeZone' => $zone,
            ];
        });
    }
}
