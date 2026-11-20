<?php

namespace App\Listeners;

use App\Events\BiometricTemplateStored;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class QueueBiometricTemplateSync implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $timeout = 180;

    // Collection must never authorize distribution. The old UDP implementation
    // can fetch multiple biometric types, so hold the entire automatic replay path.
    public function shouldQueue(BiometricTemplateStored $event): bool
    {
        return false;
    }

    public function handle(BiometricTemplateStored $event): void
    {
        // Previously queued automatic events must also be held on deployment.
        Log::info('adms.biometric_auto_replay.held_for_review', [
            'device_id' => $event->device->id,
            'employee_id' => $event->employee->id,
        ]);
    }
}
