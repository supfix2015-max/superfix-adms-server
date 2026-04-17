<?php

namespace App\Jobs;

use App\Models\RawLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class StoreRawLogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        protected int $deviceId,
        protected string $endpoint,
        protected string $payload
    ) {}

    public function handle(): void
    {
        try {
            RawLog::create([
                'device_id' => $this->deviceId,
                'endpoint' => $this->endpoint,
                'payload' => $this->payload,
                'processing_status' => 'received',
                'record_count' => $this->countRecords($this->payload),
            ]);

            Log::debug('Raw log stored', [
                'device_id' => $this->deviceId,
                'endpoint' => $this->endpoint,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to store raw log', [
                'device_id' => $this->deviceId,
                'endpoint' => $this->endpoint,
                'error' => $e->getMessage(),
            ]);

            $this->fail($e);
        }
    }

    private function countRecords(string $payload): int
    {
        $lines = preg_split('/\r?\n/', trim($payload));

        // Rough count: number of lines that contain "="
        return count(array_filter($lines, fn ($line) => str_contains($line, '=')));
    }
}
