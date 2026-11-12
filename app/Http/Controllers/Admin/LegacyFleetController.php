<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\View\View;

class LegacyFleetController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(config('legacy_attendance.browser_enabled', false), 404);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'connection' => ['nullable', 'in:online,offline'],
            'license' => ['nullable', 'in:required,not_required'],
        ]);

        $operational = Device::query()
            ->orderBy('name')
            ->get();
        $bySerial = $operational->keyBy(static fn (Device $d): string =>
            trim((string) ($d->serial_number ?: $d->sn))
        );

        $original = DB::connection('legacy_sqlsrv')->table('dbo.Device')
            ->select(['Id', 'DeviceSerialNumber', 'DeviceAliasName', 'DeviceName',
                'DeviceIP', 'DeviceStatus'])
            ->orderBy('Id')->get();

        $seen = [];
        $items = [];
        foreach ($original as $row) {
            $sn = trim((string) $row->DeviceSerialNumber);
            if ($sn === '' || isset($seen[$sn])) {
                continue;
            }
            $seen[$sn] = true;
            $app = $bySerial->get($sn);
            $items[] = $this->deviceRow(
                $sn,
                (string) ($app?->name ?: ($row->DeviceAliasName ?: ($row->DeviceName ?: 'Original device'))),
                $app,
                true,
                $row->DeviceIP,
                $row->DeviceStatus
            );
        }

        foreach ($operational as $app) {
            $sn = trim((string) ($app->serial_number ?: $app->sn));
            if ($sn !== '' && ! isset($seen[$sn])) {
                $seen[$sn] = true;
                $items[] = $this->deviceRow($sn, (string) $app->name, $app, false);
            }
        }

        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        $items = collect($items)->filter(static function (array $item) use ($filters, $search): bool {
            if ($search !== '' && ! str_contains(strtolower($item['serial'].' '.$item['name']), $search)) {
                return false;
            }
            if (($filters['connection'] ?? '') === 'online' && ! $item['online']) {
                return false;
            }
            if (($filters['connection'] ?? '') === 'offline' && $item['online']) {
                return false;
            }
            if (($filters['license'] ?? '') === 'required' && ! $item['requires_license']) {
                return false;
            }
            if (($filters['license'] ?? '') === 'not_required' && $item['requires_license']) {
                return false;
            }
            return true;
        })->values();

        $page = max(1, (int) $request->input('page', 1));
        $perPage = 22;
        $devices = new LengthAwarePaginator(
            $items->forPage($page, $perPage),
            $items->count(), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('admin.legacy.fleet', [
            'devices' => $devices,
            'filters' => $filters,
            'dispatchEnabled' => (bool) config('zkteco.dispatch_commands', false)
                && (bool) config('zkteco.command_require_token', true)
                && (bool) config('adms_pilot.enabled', false),
        ]);
    }

    public function updateLicenseRequirement(Request $request, Device $device): RedirectResponse
    {
        abort_unless(config('legacy_attendance.browser_enabled', false), 404);
        $validated = $request->validate([
            'requires_license' => ['required', 'boolean'],
        ]);

        // Does not send a vendor license key to device, or update dbo.Device.
        // This flag is an administrative tracking preference in adms.devices.
        $device->forceFill([
            'requires_license' => (bool) $validated['requires_license'],
        ])->save();

        return back()->with('success', sprintf(
            '%s: Edit License requirement is %s. No device command was sent.',
            $device->serial_number, $device->requires_license ? 'enabled' : 'disabled'
        ));
    }

    public function cancelPendingCommands(Request $request, Device $device): RedirectResponse
    {
        abort_unless(config('legacy_attendance.browser_enabled', false), 404);
        $request->validate(['confirmation' => ['required', 'in:CANCEL-PENDING']]);

        // Only unsent pending rows in isolated adms.device_commands can be
        // cancelled. Never claim a command already SENT can be recalled.
        $cancelled = $device->commands()
            ->where('status', DeviceCommand::STATUS_PENDING)
            ->update([
                'status' => DeviceCommand::STATUS_FAILED,
                'failed_at' => now(),
                'error_message' => 'Cancelled by the administrator before dispatch',
                'updated_at' => now(),
            ]);

        return back()->with('success', sprintf(
            '%d unsent commands cancelled for %s; already dispatched commands were not changed.',
            $cancelled, $device->serial_number
        ));
    }

    private function deviceRow(
        string $serial,
        string $name,
        ?Device $app,
        bool $original,
        ?string $oldIp = null,
        mixed $oldStatus = null
    ): array {
        return [
            'id' => $app?->id,
            'serial' => $serial,
            'name' => $name,
            'original' => $original,
            'online' => $app?->isOnline() ?? false,
            'last_seen' => ($app?->last_seen ?: $app?->last_heartbeat)
                ?->timezone('Asia/Dubai')->format('Y-m-d H:i:s'),
            'requires_license' => (bool) ($app?->requires_license ?? false),
            'legacy_ip' => $oldIp,
            'legacy_status' => $oldStatus,
            'operational' => $app,
        ];
    }
}
