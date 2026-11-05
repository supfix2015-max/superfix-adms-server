<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Extra deny-by-default ADMS pilot gate. IP/serial are NOT cryptographic authentication. */
class AdmsPilotGate
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('adms_pilot.enabled') !== true) {
            return response('ADMS PILOT DISABLED', 403);
        }

        $serial = trim((string) ($request->query('SN') ?: $request->query('sn') ?: $request->input('SN', '')));
        if ($serial === '' ||
            ! in_array($serial, config('adms_pilot.serials', []), true) ||
            ! in_array($request->ip(), config('adms_pilot.ips', []), true)) {
            return response('ADMS DEVICE DENIED', 403);
        }
        return $next($request);
    }
}
