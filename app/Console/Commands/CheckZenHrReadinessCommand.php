<?php

namespace App\Console\Commands;

use App\Services\ZenHrOAuthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

final class CheckZenHrReadinessCommand extends Command
{
    protected $signature = 'adms:zenhr-readiness';
    protected $description = 'Read-only AT2 ZenHR OAuth readiness: no network requests or token disclosures';

    public function handle(ZenHrOAuthService $service): int
    {
        $expected = 'https://at2.superfix.ae/admin/integrations/zenhr/callback';
        $rows = [];
        $fail = false;

        $enabled = config('zenhr.enabled') === true;
        $rows[] = ['AT2-only ZenHR feature flag', $enabled ? 'ENABLED' : 'DISABLED'];
        $fail = $fail || ! $enabled;

        $callback = config('zenhr.redirect_uri') === $expected;
        $rows[] = ['Exact ZenHR OAuth Redirect URI', $callback ? 'OK' : 'MISMATCH'];
        $fail = $fail || ! $callback;

        $hasClient = trim((string) config('zenhr.client_id')) !== '';
        $hasSecret = trim((string) config('zenhr.client_secret')) !== '';
        $rows[] = ['New ZenHR Client UID', $hasClient ? 'CONFIGURED' : 'MISSING'];
        $rows[] = ['New ZenHR Client Secret', $hasSecret ? 'CONFIGURED (not displayed)' : 'MISSING'];
        $fail = $fail || ! $hasClient || ! $hasSecret;

        foreach ([
            'admin.zenhr.index', 'admin.zenhr.connect',
            'admin.zenhr.callback', 'admin.zenhr.test',
        ] as $name) {
            $ok = Route::has($name);
            $rows[] = ["Route {$name}", $ok ? 'OK' : 'MISSING'];
            $fail = $fail || ! $ok;
        }

        try {
            $db = DB::connection('sqlsrv');
            $result = $db->selectOne(
                "SELECT DB_NAME() AS database_name,
                        OBJECT_ID(N'adms.zenhr_connections', N'U') AS table_id,
                        HAS_PERMS_BY_NAME(N'adms.zenhr_connections', N'OBJECT', N'SELECT') AS can_read,
                        HAS_PERMS_BY_NAME(N'adms.zenhr_connections', N'OBJECT', N'INSERT') AS can_insert,
                        HAS_PERMS_BY_NAME(N'adms.zenhr_connections', N'OBJECT', N'UPDATE') AS can_update"
            );

            $dbOk = strcasecmp((string) ($result->database_name ?? ''), 'Att2000_V4') === 0;
            $tableOk = $result !== null && $result->table_id !== null;
            $perms = $tableOk && (int) ($result->can_read ?? 0) === 1
                && (int) ($result->can_insert ?? 0) === 1
                && (int) ($result->can_update ?? 0) === 1;

            $rows[] = ['Operational database', $dbOk ? 'OK · Att2000_V4' : 'BLOCKER'];
            $rows[] = ['Encrypted OAuth storage table', $tableOk
                ? 'OK · adms.zenhr_connections' : 'DBA setup required'];
            $rows[] = ['SELECT+INSERT+UPDATE on token table', $perms
                ? 'OK · scoped app permissions' : 'GRANTS REQUIRED'];
            $fail = $fail || ! $dbOk || ! $tableOk || ! $perms;
        } catch (\Throwable) {
            $rows[] = ['Token storage readiness', 'FAILED · inspect SQL permissions'];
            $fail = true;
        }

        $rows[] = ['Attendance outbound sync', 'DISABLED · no API punch writes'];
        $this->table(['ZenHR AT2 read-only check', 'Result'], $rows);
        $this->warn('No OAuth secrets, personal data or tokens were printed; no API calls or SQL writes.');

        return $fail ? self::FAILURE : self::SUCCESS;
    }
}
