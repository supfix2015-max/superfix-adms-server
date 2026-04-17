<?php

namespace App\Console\Commands;

use App\Services\UserSyncService;
use Illuminate\Console\Command;

class AdmsCleanupUsersCommand extends Command
{
    protected $signature = 'adms:cleanup-users {--refresh-only : Rebuild sync-safe flags without deleting legacy malformed rows}';

    protected $description = 'Clean malformed legacy ADMS employee rows and rebuild the device-safe sync dataset';

    public function handle(UserSyncService $userSyncService): int
    {
        $deleted = 0;

        if (! $this->option('refresh-only')) {
            $deleted = $userSyncService->cleanupLegacyMalformedUsers();
        }

        $summary = $userSyncService->refreshDeviceSafeDataset();

        $this->info(sprintf(
            'ADMS user cleanup completed. deleted=%d total=%d sync_safe=%d rejected=%d',
            $deleted,
            $summary['total'],
            $summary['sync_safe'],
            $summary['rejected'],
        ));

        return self::SUCCESS;
    }
}
