<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Explicit two-host split. During cutover set ADMS_INSTANCE_ROLE=devices
 * on AT1 and ADMS_INSTANCE_ROLE=console on AT2. Both may read shared SQL.
 * No device requests are proxied/redirected to HTTPS by Laravel.
 */
final class AdmsInstanceRoleGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $role = (string) config('adms_instance.role', 'transition');
        if ($role === 'transition') {
            return $next($request);
        }

        if (! in_array($role, ['devices', 'console'], true)) {
            return response('ADMS instance role misconfigured', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $host = strtolower($request->getHost());
        $expected = (string) config('adms_instance.'.
            ($role === 'devices' ? 'device_host' : 'console_host'));
        if (! hash_equals(strtolower($expected), $host)) {
            return response('Unknown ADMS host', Response::HTTP_NOT_FOUND);
        }

        $deviceEndpoint = $request->is('iclock', 'iclock/*', 'api/device', 'api/device/*');
        if ($role === 'console' && $deviceEndpoint) {
            return response('Device ingress lives on AT1', Response::HTTP_NOT_FOUND);
        }

        if ($role === 'devices' && ! $deviceEndpoint) {
            // Coolify upstream health check, no auth or device control surface.
            if ($request->isMethod('GET') && $request->path() === '/') {
                return response('AT1 device gateway ready', Response::HTTP_OK)
                    ->header('Content-Type', 'text/plain; charset=UTF-8')
                    ->header('Cache-Control', 'no-store');
            }
            return response('Admin console lives on AT2', Response::HTTP_NOT_FOUND);
        }

        return $next($request);
    }
}
