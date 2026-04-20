<?php

namespace App\Listeners;

use App\Events\BiometricTemplateStored;
use App\Services\BiometricSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class QueueBiometricTemplateSync implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $timeout = 180;

    public function handle(BiometricTemplateStored $event): void
    {
        app(BiometricSyncService::class)->syncEmployeeTemplates($event->employee, [
            'source_device' => $event->device,
            'sync_keys' => $event->syncKeys,
            'prefer_udp' => true,
            'overwrite' => true,
        ]);
    }
}
