<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\DB;
use LogicException;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // AT2 runs behind the Coolify TLS reverse proxy and may be tagged
        // APP_ENV=staging. Generate secure Laravel links for that exact
        // HTTPS app URL even when PHP receives proxy-to-container HTTP.
        // Do not change unrelated AT1 device ingress behavior.
        $appUrl = (string) config('app.url');
        $at2OverHttps = parse_url($appUrl, PHP_URL_HOST) === 'at2.superfix.ae'
            && parse_url($appUrl, PHP_URL_SCHEME) === 'https';
        if (config('app.env') === 'production' || $at2OverHttps) {
            URL::forceScheme('https');
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // In this branch, original and app data intentionally share ONE
        // database but NEVER a schema. Enforce at runtime, including artisan
        // migrate, before Laravel can create or alter an unqualified table.
        if (app()->environment('testing')) {
            return;
        }

        // During the Docker build, Composer discovers packages before SQL
        // secrets exist. The only permitted exception is that exact CLI
        // command with missing deployment configuration.
        if (app()->runningInConsole()
            && ($_SERVER['argv'][1] ?? '') === 'package:discover'
            && trim((string) env('ADMS_SQLSRV_DATABASE', '')) === ''
            && trim((string) env('LEGACY_SQLSRV_DATABASE', '')) === '') {
            return;
        }

        if (config('database.default') !== 'sqlsrv'
            || strcasecmp((string) config('database.connections.sqlsrv.schema'), 'adms') !== 0) {
            throw new LogicException('Unsafe SQL Server-only connection configuration.');
        }

        $identity = DB::connection('sqlsrv')->selectOne(
            'SELECT DB_NAME() AS database_name, SCHEMA_NAME() AS default_schema'
        );
        if (strcasecmp((string) ($identity->database_name ?? ''), 'Att2000_V4') !== 0
            || strcasecmp((string) ($identity->default_schema ?? ''), 'adms') !== 0) {
            throw new LogicException(
                'Unsafe SQL Server identity: migration/application user must use Att2000_V4 with DEFAULT_SCHEMA=adms.'
            );
        }
    }
}
