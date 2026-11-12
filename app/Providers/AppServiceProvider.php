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
        if (config('app.env') === 'production') {
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
