<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/devices';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Terminals may share a reverse-proxy/NAT IP. Preserve the regular API
        // limit, but do not make an entire fleet share its 60-request bucket.
        // Serial numbers are untrusted identifiers, not authentication.
        RateLimiter::for('zkteco-ingress', function (Request $request) {
            $value = $request->query('SN') ?: $request->query('sn') ?: $request->input('SN', '');
            $serial = is_string($value) ? trim($value) : '';
            $ip = $request->ip() ?? 'unknown';

            if (! preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $serial)) {
                return [
                    Limit::perMinute(1800)->by('ingress-ip:'.$ip),
                    Limit::perMinute(60)->by('invalid-serial-ip:'.$ip),
                ];
            }

            return [
                Limit::perMinute(1800)->by('ingress-ip:'.$ip),
                Limit::perMinute(120)->by('terminal:'.strtolower($serial)),
            ];
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware(['throttle:zkteco-ingress', \Illuminate\Routing\Middleware\SubstituteBindings::class])
                ->group(base_path('routes/device.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
