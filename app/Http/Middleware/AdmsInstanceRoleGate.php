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

        // Single Coolify application bound to both domains:
        // AT1 accepts ONLY device protocol, AT2 accepts ONLY console/API.
        if ($role === 'dual') {
            $host = strtolower($request->getHost());
            $deviceHost = strtolower((string) config('adms_instance.device_host'));
            $consoleHost = strtolower((string) config('adms_instance.console_host'));
            $deviceEndpoint = $request->is('iclock', 'iclock/*', 'api/device', 'api/device/*');

            // Internal container health check bypasses public Host restrictions.
            if ($request->isMethod('GET') && $request->is('api/health')
                && in_array($host, ['127.0.0.1', 'localhost'], true)) {
                return $next($request);
            }

            if ($host === $deviceHost) {
                if ($deviceEndpoint) {
                    return $next($request);
                }
                if ($request->isMethod('GET') && $request->path() === '/') {
                    return response('AT1 device gateway ready', Response::HTTP_OK)
                        ->header('Content-Type', 'text/plain; charset=UTF-8')
                        ->header('Cache-Control', 'no-store');
                }
                return response('Admin console lives on AT2', Response::HTTP_NOT_FOUND);
            }

            if ($host === $consoleHost) {
                if ($deviceEndpoint) {
                    return response('Device ingress lives on AT1', Response::HTTP_NOT_FOUND);
                }
                return $next($request);
            }

            return response('Unknown ADMS host', Response::HTTP_NOT_FOUND);
        }

        if (! in_array($role, ['devices', 'console'], true)) {
            return response('ADMS instance role misconfigured', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // Docker health probe uses Host: 127.0.0.1:8080; process
        // before the public-host check so both instances remain healthy.
        if ($request->isMethod('GET') && $request->is('api/health')
            && in_array($request->getHost(), ['127.0.0.1', 'localhost'], true)) {
            return $next($request);
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
