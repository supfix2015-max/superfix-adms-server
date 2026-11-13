<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADMS admission gate. ZKTeco terminals initiate outbound requests from
 * arbitrary/dynamic WAN IPs (e.g., DU behind carrier-grade NAT).
 * A serial allowlist is required; an IP allowlist is optional.
 *
 * WARNING: serial numbers (and IP addresses) are not authentication.
 * ZKTecoService enforces device tokens separately when supported.
 */
class AdmsPilotGate
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('adms_pilot.enabled') !== true) {
            return response('ADMS PILOT DISABLED', Response::HTTP_FORBIDDEN);
        }

        $serial = trim((string) (
            $request->query('SN')
            ?: $request->query('sn')
            ?: $request->input('SN', '')
        ));
        $serials = config('adms_pilot.serials', []);
        $explicitlyAllowed = $serial !== '' && in_array($serial, $serials, true);
        $historicallyRegistered = false;

        // Allow original registered devices when explicitly enabled.
        // No blanket wildcard. The source login is SELECT only.
        if (! $explicitlyAllowed && $serial !== ''
            && preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $serial)
            && config('adms_pilot.allow_historical', false)) {
            $historicallyRegistered = Cache::store('file')->remember(
                'adms_pilot_original_device_'.hash('sha256', $serial),
                120,
                static fn (): bool => DB::connection('legacy_sqlsrv')
                    ->table('dbo.Device')
                    ->where('DeviceSerialNumber', $serial)
                    ->exists()
            );
        }

        if (! $explicitlyAllowed && ! $historicallyRegistered) {
            return response('ADMS DEVICE DENIED', Response::HTTP_FORBIDDEN);
        }

        // Empty IP list = no network restriction. Source IP changes with NAT.
        $ips = config('adms_pilot.ips', []);
        if ($ips !== [] && ! in_array($request->ip(), $ips, true)) {
            return response('ADMS NETWORK DENIED', Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
