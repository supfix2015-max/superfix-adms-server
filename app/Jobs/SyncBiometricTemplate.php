<?php

namespace App\Jobs;

use App\Models\BiometricTemplate;
use App\Models\Device;
use App\Services\BiometricSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncBiometricTemplate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [30, 120, 300];

    public function __construct(
        protected int $biometricTemplateId,
        protected int $targetDeviceId,
        protected bool $overwrite = true,
    ) {
        $this->onQueue('device-syncs');
    }

    public function handle(BiometricSyncService $biometricSyncService): void
    {
        $template = BiometricTemplate::query()->with(['employee', 'device'])->findOrFail($this->biometricTemplateId);
        $targetDevice = Device::query()->findOrFail($this->targetDeviceId);

        $biometricSyncService->pushTemplateToDevice($targetDevice, $template, $this->overwrite);
    }

    public function failed(?Throwable $exception): void
    {
        $template = BiometricTemplate::query()->with(['employee', 'device'])->find($this->biometricTemplateId);
        $targetDevice = Device::query()->find($this->targetDeviceId);

        if (! $template || ! $targetDevice) {
            return;
        }

        app(BiometricSyncService::class)->flagSyncFailure(
            $template,
            $targetDevice,
            $exception?->getMessage() ?? 'Biometric template push failed after all retries.',
        );
    }
}
