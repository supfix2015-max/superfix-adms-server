<?php

namespace App\Console\Commands;

use App\Services\ZenHrOAuthService;
use Illuminate\Console\Command;

final class CheckZenHrConnectivityCommand extends Command
{
    protected $signature = 'adms:zenhr-connectivity';

    protected $description = 'AT2-only: refresh expired OAuth token if needed and verify who_am_i; never display credentials';

    public function handle(ZenHrOAuthService $zenHr): int
    {
        try {
            $identity = $zenHr->testStoredConnection();
            $info = $identity['token_info'] ?? [];
            $props = $info['props'] ?? [];
            $scopes = $info['scopes'] ?? [];

            $this->table(['ZenHR connectivity', 'Result'], [
                ['OAuth/API', 'CONNECTED · who_am_i OK'],
                ['Company ID', is_scalar($props['company_id'] ?? null) ? (string) $props['company_id'] : 'UNAVAILABLE'],
                ['Default branch ID', is_scalar($props['branch_id'] ?? null) ? (string) $props['branch_id'] : 'UNAVAILABLE'],
                ['Granted scopes', is_array($scopes) ? implode(', ', array_filter($scopes, 'is_string')) : 'UNAVAILABLE'],
                ['Outbound attendance POST', 'DISABLED · no punches sent'],
            ]);
            $this->info('No attendance was posted to ZenHR.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            // Connection errors emitted by ZenHrOAuthService are sanitized;
            // never show vendor payloads, bearer tokens or refresh tokens.
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
