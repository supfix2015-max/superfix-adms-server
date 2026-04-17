<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceSync;
use App\Repositories\DeviceCommandRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CommandService
{
    public function __construct(
        protected DeviceCommandRepository $repository,
        protected ZKTecoCommandFormatter $formatter,
    ) {}

    public function queueCommand(int $deviceId, string $command, array $parameters = [], ?string $notes = null, array $context = []): DeviceCommand
    {
        $normalizedCommand = $this->normalizeCommandName($command);
        $protocolCommand = ! in_array($normalizedCommand, DeviceCommand::KNOWN_COMMANDS, true)
            ? trim($normalizedCommand)
            : null;

        $normalizedParameters = $this->normalizeParameters(array_merge($parameters, [
            'notes' => $notes,
        ]));

        $fingerprint = $context['fingerprint']
            ?? $this->buildFingerprint($normalizedCommand, $protocolCommand, $normalizedParameters, $context);

        if (! ($context['force'] ?? false)) {
            $existing = $this->repository->findReusableCommand($deviceId, $fingerprint);

            if (
                $existing
                && (
                    $existing->status === DeviceCommand::STATUS_DONE
                    || $existing->expires_at === null
                    || $existing->expires_at->isFuture()
                )
            ) {
                return $existing;
            }
        }

        return DeviceCommand::query()->create([
            'device_id' => $deviceId,
            'device_sync_id' => $context['device_sync_id'] ?? null,
            'employee_id' => $context['employee_id'] ?? null,
            'command' => $normalizedCommand,
            'command_payload' => $protocolCommand,
            'parameters' => $normalizedParameters,
            'fingerprint' => $fingerprint,
            'status' => DeviceCommand::STATUS_PENDING,
            'created_by' => $this->resolveCreatedById($context['created_by'] ?? null),
            'expires_at' => $context['expires_at'] ?? now()->addSeconds((int) config('zkteco.command_ttl_seconds', 900)),
        ]);
    }

    public function dispatchNextCommand(Device $device): ?string
    {
        $maxRetries = (int) config('zkteco.command_max_retries', 3);
        $retryAfter = now()->subSeconds((int) config('zkteco.command_retry_after_seconds', 30));

        $this->repository->expireCommands($device->id);
        $this->repository->markRetryLimitExceeded($device->id, $maxRetries);

        $command = $this->repository->findNextPending($device)
            ?? $this->repository->findRetryableSent($device, $maxRetries, $retryAfter);

        if (! $command) {
            return null;
        }

        try {
            $payload = $this->sanitizeOutgoingPayload($this->formatter->format($command));
        } catch (\Throwable $exception) {
            $command->forceFill([
                'status' => DeviceCommand::STATUS_FAILED,
                'failed_at' => now(),
                'error_message' => $exception->getMessage(),
            ])->save();

            return null;
        }

        $this->markCommandAsDispatched($command, $payload);

        if ($command->deviceSync && $command->deviceSync->sync_started_at === null) {
            $command->deviceSync->forceFill([
                'sync_started_at' => now(),
                'status' => DeviceSync::STATUS_DISPATCHING,
                'last_dispatched_at' => now(),
            ])->save();
        } elseif ($command->deviceSync) {
            $command->deviceSync->forceFill([
                'last_dispatched_at' => now(),
            ])->save();
        }

        return $payload;
    }

    public function resolveCommandForReply(Device $device, array $record): ?DeviceCommand
    {
        $commandId = $record['ID'] ?? $record['CmdID'] ?? $record['id'] ?? null;

        if ($commandId !== null && $commandId !== '') {
            return DeviceCommand::query()
                ->where('device_id', $device->id)
                ->whereKey($commandId)
                ->first();
        }

        $sentCandidates = DeviceCommand::query()
            ->where('device_id', $device->id)
            ->where('status', DeviceCommand::STATUS_SENT)
            ->latest('sent_at')
            ->limit(5)
            ->get();

        $replyCommand = strtoupper(trim((string) ($record['CMD'] ?? $record['Command'] ?? '')));

        if ($replyCommand !== '') {
            $matchedSentCommand = $sentCandidates->first(function (DeviceCommand $candidate) use ($replyCommand): bool {
                $payload = strtoupper($candidate->command_payload ?: $this->formatter->format($candidate));

                return str_contains($payload, $replyCommand) || str_contains(strtoupper($candidate->command), $replyCommand);
            });

            if ($matchedSentCommand) {
                return $matchedSentCommand;
            }

            return DeviceCommand::query()
                ->where('device_id', $device->id)
                ->where('status', DeviceCommand::STATUS_DONE)
                ->latest('acknowledged_at')
                ->limit(5)
                ->get()
                ->first(function (DeviceCommand $candidate) use ($replyCommand): bool {
                    $payload = strtoupper($candidate->command_payload ?: $this->formatter->format($candidate));

                    return str_contains($payload, $replyCommand) || str_contains(strtoupper($candidate->command), $replyCommand);
                });
        }

        return $sentCandidates->count() === 1 ? $sentCandidates->first() : null;
    }

    public function recordDeviceReply(DeviceCommand $command, array $record, string $fallback): void
    {
        $successful = $this->isSuccessfulReply($record['Return'] ?? null);
        $wasTerminal = in_array($command->status, [DeviceCommand::STATUS_DONE, DeviceCommand::STATUS_FAILED], true);

        if ($command->status === DeviceCommand::STATUS_DONE && $successful) {
            return;
        }

        if ($successful) {
            $command->forceFill([
                'status' => DeviceCommand::STATUS_DONE,
                'response' => $this->buildResponseText($record, $fallback),
                'response_payload' => $record,
                'executed_at' => now(),
                'acknowledged_at' => now(),
                'error_message' => null,
                'failed_at' => null,
            ])->save();
        } else {
            $maxRetries = (int) config('zkteco.command_max_retries', 3);
            $hasAttemptsRemaining = $command->retry_count < $maxRetries
                && ($command->expires_at === null || $command->expires_at->isFuture());

            $command->forceFill([
                'status' => $hasAttemptsRemaining ? DeviceCommand::STATUS_PENDING : DeviceCommand::STATUS_FAILED,
                'response' => $this->buildResponseText($record, $fallback),
                'response_payload' => $record,
                'acknowledged_at' => now(),
                'error_message' => $record['Message'] ?? $record['Error'] ?? 'Device command failed',
                'failed_at' => now(),
            ])->save();
        }

        if (! $wasTerminal) {
            $this->updateSyncBatchProgress($command);
        }
    }

    public function recoverFailedCommands(): int
    {
        $maxRetries = (int) config('zkteco.command_max_retries', 3);
        $recovered = 0;

        foreach ($this->repository->retryableFailures($maxRetries) as $command) {
            $command->forceFill([
                'status' => DeviceCommand::STATUS_PENDING,
                'error_message' => null,
            ])->save();
            $recovered++;
        }

        return $recovered;
    }

    private function normalizeCommandName(string $command): string
    {
        return match (strtoupper(trim($command))) {
            'REBOOT' => 'RESTART',
            'SYNC' => 'SYNC_EMPLOYEES',
            'PULL_USERS' => 'PULL_EMPLOYEES',
            'PULL_LOGS' => 'PULL_ATTENDANCE',
            default => trim($command),
        };
    }

    private function normalizeParameters(array $parameters): array
    {
        $parameters = array_filter($parameters, static fn ($value) => $value !== null && $value !== '');
        Arr::sortRecursive($parameters);

        return $parameters;
    }

    private function resolveCreatedById(?int $candidateUserId = null): ?int
    {
        $userId = $candidateUserId ?? auth()->id();

        if (! $userId) {
            return null;
        }

        return DB::table('users')->where('id', $userId)->exists()
            ? (int) $userId
            : null;
    }

    private function buildFingerprint(string $command, ?string $protocolCommand, array $parameters, array $context): string
    {
        return sha1(json_encode([
            'command' => $command,
            'protocol_command' => $protocolCommand,
            'parameters' => $parameters,
            'employee_id' => $context['employee_id'] ?? null,
            'device_sync_id' => $context['device_sync_id'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    private function sanitizeOutgoingPayload(string $payload): string
    {
        $payload = trim(str_replace(["\r", "\n"], ' ', $payload));

        return str_starts_with($payload, 'C:')
            ? $payload
            : preg_replace('/^C\s*:\s*/', 'C:', $payload) ?? $payload;
    }

    private function markCommandAsDispatched(DeviceCommand $command, string $payload): void
    {
        $retryCount = $command->retry_count;

        if ($command->status === DeviceCommand::STATUS_SENT) {
            $retryCount++;
        } elseif ($command->sent_at !== null || $command->failed_at !== null) {
            $retryCount++;
        }

        $command->forceFill([
            'status' => DeviceCommand::STATUS_SENT,
            'sent_at' => now(),
            'retry_count' => $retryCount,
            'command_payload' => $payload,
            'error_message' => null,
        ])->save();
    }

    private function isSuccessfulReply(mixed $returnCode): bool
    {
        return in_array(strtoupper(trim((string) $returnCode)), ['0', '1', 'OK', 'SUCCESS', 'TRUE'], true);
    }

    private function buildResponseText(array $record, string $fallback): string
    {
        if (! empty($record['CMD'])) {
            return trim((string) $record['CMD']);
        }

        if (! empty($record['Message'])) {
            return trim((string) $record['Message']);
        }

        if ($record !== []) {
            return collect($record)
                ->map(static fn ($value, $key): string => "{$key}=".trim((string) $value))
                ->implode("\n");
        }

        return trim($fallback);
    }

    private function updateSyncBatchProgress(DeviceCommand $command): void
    {
        $sync = $command->deviceSync;

        if (! $sync) {
            return;
        }

        $processed = $sync->processed_records + 1;
        $successful = $sync->successful_records + ($command->status === DeviceCommand::STATUS_DONE ? 1 : 0);
        $failed = $sync->failed_records + ($command->status === DeviceCommand::STATUS_FAILED ? 1 : 0);

        $attributes = [
            'processed_records' => $processed,
            'successful_records' => $successful,
            'failed_records' => $failed,
        ];

        if ($processed >= $sync->queued_records) {
            $attributes['sync_completed_at'] = now();
            $attributes['status'] = $failed > 0
                ? DeviceSync::STATUS_COMPLETED_WITH_ERRORS
                : DeviceSync::STATUS_COMPLETED;
        }

        $sync->forceFill($attributes)->save();

        if (($attributes['status'] ?? null) === DeviceSync::STATUS_COMPLETED) {
            $sync->device->forceFill([
                'last_user_sync_version' => $sync->version,
                'last_user_sync_at' => now(),
                'pending_full_resync' => false,
            ])->save();
        }
    }
}
