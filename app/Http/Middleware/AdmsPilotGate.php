<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
        if ($serial === '' || ! in_array($serial, $serials, true)) {
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
