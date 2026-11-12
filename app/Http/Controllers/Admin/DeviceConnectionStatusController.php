<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Compare the original dbo.Device list with actual inbound ADMS heartbeats
 * stored in the isolated adms.devices table. READ ONLY.
 */
class DeviceConnectionStatusController extends Controller
{
    public function index(): View
    {
        abort_unless(config('legacy_attendance.browser_enabled', false), 404);

        $source = Cache::store('file')->remember('adms_legacy_device_connections_v1', 120, function (): array {
            $legacy = DB::connection('legacy_sqlsrv');

            $devices = $legacy->table('dbo.Device')
                ->select(['Id', 'DeviceSerialNumber', 'DeviceAliasName', 'DeviceName', 'DeviceStatus', 'DeviceIP'])
                ->orderBy('Id')
                ->get();

            $serials = $devices
                ->pluck('DeviceSerialNumber')
                ->filter(static fn ($value) => trim((string) $value) !== '')
                ->unique()->values()->all();

            $recentDates = $serials === [] ? collect() :
                $legacy->table('dbo.Attendance')
                    ->whereIn('DeviceSerialNumber', $serials)
                    ->select('DeviceSerialNumber')
                    ->selectRaw('MAX([VerifyTime]) AS LastHistoricalPunch')
                    ->groupBy('DeviceSerialNumber')->get()
                    ->keyBy('DeviceSerialNumber');

            return $devices->map(static fn (object $device): array => [
                'name' => $device->DeviceAliasName ?: ($device->DeviceName ?: 'Unnamed device'),
                'serial' => trim((string) $device->DeviceSerialNumber),
                'legacy_ip' => $device->DeviceIP,
                'legacy_status' => $device->DeviceStatus,
                'legacy_punch' => $recentDates->get((string) $device->DeviceSerialNumber)?->LastHistoricalPunch,
            ])->all();
        });

        $serials = array_values(array_unique(array_filter(array_column($source, 'serial'))));
        $operational = $serials === [] ? collect() :
            Device::query()->whereIn('serial_number', $serials)->orWhereIn('sn', $serials)
                ->get(['id', 'serial_number', 'sn', 'status', 'last_seen', 'last_heartbeat']);
        $operationalBySerial = $operational->keyBy(static fn (Device $d): string =>
            trim((string) ($d->serial_number ?: $d->sn))
        );

        $timeZone = (string) (config('legacy_attendance.attendance_wallclock_timezone') ?: 'Asia/Dubai');
        $ttl = (int) config('zkteco.heartbeat_ttl_minutes', 5);

        $rows = array_map(static function (array $row) use ($operationalBySerial, $ttl, $timeZone): array {
            $activeDevice = $operationalBySerial->get($row['serial']);
            $lastSeen = $activeDevice?->last_seen ?: $activeDevice?->last_heartbeat;
            $online = $activeDevice !== null && $activeDevice->isOnline();
            return [
                ...$row,
                'last_historical_punch_formatted' => $row['legacy_punch']
                    ? CarbonImmutable::parse($row['legacy_punch'], $timeZone)->format('d M Y H:i:s')
                    : null,
                'last_contact' => $lastSeen?->copy()->timezone($timeZone)->format('d M Y H:i:s'),
                'online' => $online,
                'registered_in_app' => $activeDevice !== null,
            ];
        }, $source);

        $onlineCount = count(array_filter($rows, static fn (array $r): bool => $r['online']));
        return view('admin.device_connections.index', [
            'rows' => $rows,
            'onlineCount' => $onlineCount,
            'historicalCount' => count($rows),
            'timeZone' => $timeZone,
            'ttl' => $ttl,
        ]);
    }
}
