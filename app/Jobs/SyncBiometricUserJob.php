<?php

namespace App\Jobs;

use App\Services\ZKTecoBiometricSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncBiometricUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(
        protected string $userId,
        protected string $sourceDeviceIp,
        protected array $targetDeviceIps,
        protected array $options = [],
    ) {
        $this->onQueue('device-syncs');
    }

    public function handle(ZKTecoBiometricSyncService $syncService): void
    {
        $syncService->syncForUser($this->userId, $this->sourceDeviceIp, $this->targetDeviceIps, $this->options);
    }
}
