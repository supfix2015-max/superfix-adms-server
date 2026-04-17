<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\UserSyncService;
use Illuminate\Console\Command;

class AdmsQueueUserSyncCommand extends Command
{
    protected $signature = 'adms:queue-user-sync {--device=* : Limit sync to specific serial numbers} {--force : Queue even if the dataset hash is unchanged}';

    protected $description = 'Queue a safe full user sync batch for one or more ADMS devices';

    public function handle(UserSyncService $userSyncService): int
    {
        $serials = collect((array) $this->option('device'))
            ->map(static fn (string $serial): string => trim($serial))
            ->filter()
            ->values();

        $force = (bool) $this->option('force');
        $queued = 0;

        if ($serials->isEmpty()) {
            $queued = $userSyncService->queueFullSyncForAllDevices($force, 'console');
            $this->info("Queued user sync batches for {$queued} device(s).");

            return self::SUCCESS;
        }

        foreach (Device::query()->whereIn('serial_number', $serials->all())->get() as $device) {
            if ($userSyncService->queueFullSyncForDevice($device, $force, 'console')) {
                $queued++;
            }
        }

        $this->info("Queued user sync batches for {$queued} selected device(s).");

        return self::SUCCESS;
    }
}
