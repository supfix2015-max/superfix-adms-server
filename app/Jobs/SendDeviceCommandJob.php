<?php

namespace App\Jobs;

use App\Models\Device;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendDeviceCommandJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        protected int $deviceCommandId
    ) {}

    public function handle(): void
    {
        try {
            // This is a placeholder. In a real implementation, you would
            // use HTTP client to send commands to the device via its IP
            Log::info('Device command job executed', [
                'device_command_id' => $this->deviceCommandId,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send device command', [
                'device_command_id' => $this->deviceCommandId,
                'error' => $e->getMessage(),
            ]);

            $this->fail($e);
        }
    }
}
