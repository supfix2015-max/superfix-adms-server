<?php

namespace App\Jobs;

use App\Services\UserSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PrepareUserSyncBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        protected int $deviceSyncId
    ) {
        $this->onQueue('device-syncs');
    }

    public function handle(UserSyncService $userSyncService): void
    {
        $userSyncService->dispatchQueuedBatch($this->deviceSyncId);
    }
}
