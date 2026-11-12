<?php

namespace App\Services;

use App\Events\BiometricTemplateStored;
use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\EmployeeBiometric;
use App\Models\ErrorLog;
use App\Models\RawLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ZKTecoService
{
    public function __construct(
        protected ZKTecoPayloadParser $payloadParser,
        protected ZKTecoCommandFormatter $commandFormatter,
        protected DeviceService $deviceService,
        protected UserSyncService $userSyncService,
        protected CommandService $commandService,
        protected SystemClockService $systemClockService,
    ) {}

    public function handleCdata(Request $request): string
    {
        $context = $this->buildContext($request, '/iclock/cdata');
        $rawLog = $this->createRawLog(null, $context);

        try {
            $device = $this->resolveDevice($request, $context);
            $this->attachRawLogDevice($rawLog, $device);
            $this->touchDevice($device, $request, $context);

            // PUSH SDK §2.3: GET /iclock/cdata?SN=...&type=time must
            // return an offset-aware local clock, not GET OPTION FROM.
            // The old generic GET branch accidentally returned options
            // and could leave devices displaying the container's UTC time.
            if ($request->isMethod('GET')
                && strcasecmp(trim((string) $request->query('type', '')), 'time') === 0) {
                $response = 'Time='.$this->systemClockService->formatForPushTimeResponse()."\r\n";
                $this->finishRawLog($rawLog, 'processed', 0, [
                    'mode' => 'time_sync',
                    'timezone' => $this->systemClockService->timezoneName(),
                ]);
                $this->logRequestHandled($context['endpoint'], $device, ['mode' => 'time_sync']);

                return $response;
            }

            if ($this->shouldRespondWithOptions($request, $context)) {
                $response = $this->buildOptionsResponse($device);
                $this->finishRawLog($rawLog, 'processed', 0, ['mode' => 'options'] + $context['metadata']);
                $this->logRequestHandled($context['endpoint'], $device, ['mode' => 'options']);

                return $response;
            }

            $result = $this->processCdataPayload($device, $context);

            $this->finishRawLog(
                $rawLog,
                'processed',
                $result['count'],
                array_merge(['type' => $result['type']], $result['metadata'])
            );

            if ($result['warnings'] !== []) {
                Log::warning('ZKTeco payload handled with warnings', [
                    'endpoint' => $context['endpoint'],
                    'device_id' => $device->id,
                    'serial_number' => $device->serial_number,
                    'warnings' => $result['warnings'],
                ]);
            }

            $this->logRequestHandled($context['endpoint'], $device, [
                'type' => $result['type'],
                'record_count' => $result['count'],
            ]);

            return $result['response'];
        } catch (\Throwable $exception) {
            $this->finishRawLog($rawLog, 'failed', 0, $context['metadata'], $exception->getMessage());
            $this->recordError($rawLog?->device, 'api_error', $exception->getMessage(), 'high');

            throw $exception;
        }
    }

    /**
     * Legacy PUSH variant: after a SET OPTIONS DateTime command the device
     * may request /iclock/rtdata?SN=...&type=time.
     */
    public function handleRealtimeData(Request $request): string
    {
        $context = $this->buildContext($request, '/iclock/rtdata');
        $rawLog = $this->createRawLog(null, $context);

        try {
            $device = $this->resolveDevice($request, $context);
            $this->attachRawLogDevice($rawLog, $device);
            $this->touchDevice($device, $request, $context);

            if (! $request->isMethod('GET')
                || strcasecmp(trim((string) $request->query('type', '')), 'time') !== 0) {
                $this->finishRawLog($rawLog, 'processed', 0, ['mode' => 'unsupported_rtdata']);
                return "OK\r\n";
            }

            $this->finishRawLog($rawLog, 'processed', 0, [
                'mode' => 'realtime_time_sync',
                'timezone' => $this->systemClockService->timezoneName(),
            ]);

            return $this->systemClockService->formatForRealtimeTimeResponse()."\r\n";
        } catch (\Throwable $e) {
            $this->finishRawLog($rawLog, 'failed', 0, $context['metadata'], $e->getMessage());
            throw $e;
        }
    }

    public function handleGetRequest(Request $request): string
    {
        $context = $this->buildContext($request, '/iclock/getrequest');
        $rawLog = $this->createRawLog(null, $context);

        try {
            $device = $this->resolveDevice($request, $context);
            $this->attachRawLogDevice($rawLog, $device);
            $this->touchDevice($device, $request, $context);

            // Dedicated time-only response is independent from the normal
            // command queue. It works for ALL explicitly eligible registered
            // devices when opted in, even while ordinary command dispatch
            // remains OFF. No biometric/reboot/admin command is exposed.
            $clockOnly = $this->unverifiedLegacyClockCommand($device);
            if ($clockOnly !== null) {
                $this->finishRawLog($rawLog, 'processed', 1, [
                    'mode' => 'legacy_time_only_sent',
                    'command' => 'SET OPTIONS DateTime',
                    'authenticated' => $this->hasAuthenticatedDeviceToken($request, $device),
                ]);

                return $clockOnly."\r\n";
            }

            // An explicitly enabled, one-time retrospective ATTLOG read for
            // the named original terminal. It is a read request only; it does
            // NOT expose arbitrary queued commands to tokenless devices.
            $backfillCommand = $this->unverifiedHistoricalAttendanceQuery($device);
            if ($backfillCommand !== null) {
                $this->finishRawLog($rawLog, 'processed', 1, [
                    'mode' => 'legacy_attlog_backfill_sent',
                    'command' => 'DATA QUERY ATTLOG',
                    'authenticated' => $this->hasAuthenticatedDeviceToken($request, $device),
                ]);

                return $backfillCommand."\r\n";
            }

            // Ordinary commands and their replies still require a verified
            // per-device token; serial-only HTTP access grants time only.
            if (config('zkteco.command_require_token', true)
                && ! $this->hasAuthenticatedDeviceToken($request, $device)) {
                $this->finishRawLog($rawLog, 'processed', 0, [
                    'command' => null, 'command_authorization' => 'required',
                ]);
                return "OK\r\n";
            }

            // Read-only pilot mode: never dispatch a queued device command or
            // auto-enroll users from copied MySQL state. The device may poll safely.
            if (! config('zkteco.dispatch_commands', false)) {
                $this->finishRawLog($rawLog, 'processed', 0, ['command' => null, 'dispatch_disabled' => true]);
                $this->logRequestHandled($context['endpoint'], $device, ['record_count' => 0, 'dispatch_disabled' => true]);
                return "OK\r\n";
            }

            // Opt-in periodic clock correction is only queued for a
            // token-authenticated device, never for serial-only HTTP clients.
            $this->ensureAutomaticTimeSyncQueued($device);
            $this->ensureAutomaticEmployeePullQueued($device);

            $command = $this->findDispatchableCommand($device);

            if (! $command) {
                $this->finishRawLog($rawLog, 'processed', 0, ['command' => null]);
                $this->logRequestHandled($context['endpoint'], $device, ['record_count' => 0]);

                return "OK\r\n";
            }

            $payload = $this->commandFormatter->format($command);
            $this->markCommandAsDispatched($command, $payload);

            $this->finishRawLog($rawLog, 'processed', 1, [
                'command_id' => $command->id,
                'command' => $command->command,
                'payload' => $payload,
            ]);

            $this->logRequestHandled($context['endpoint'], $device, [
                'record_count' => 1,
                'command_id' => $command->id,
                'retry_count' => $command->retry_count,
            ]);

            return "{$payload}\r\n";
        } catch (\Throwable $exception) {
            $this->finishRawLog($rawLog, 'failed', 0, $context['metadata'], $exception->getMessage());
            $this->recordError($rawLog?->device, 'api_error', $exception->getMessage(), 'high');

            throw $exception;
        }
    }

    public function handleDeviceCommandReply(Request $request): string
    {
        $context = $this->buildContext($request, '/iclock/devicecmd');
        $rawLog = $this->createRawLog(null, $context);

        try {
            $device = $this->resolveDevice($request, $context);
            $this->attachRawLogDevice($rawLog, $device);
            $this->touchDevice($device, $request, $context);

            // A spoofed serial must never acknowledge an ordinary queued
            // command. The only tokenless exception recognizes the exact
            // temporary time-only command ID, and modifies no queue rows.
            if (config('zkteco.command_require_token', true)
                && ! $this->hasAuthenticatedDeviceToken($request, $device)) {
                if ($this->acceptTimeOnlyAcknowledgement($device, $context)) {
                    $this->finishRawLog($rawLog, 'processed', 0, [
                        'mode' => 'legacy_time_only_reply',
                        'authenticated' => false,
                    ]);
                    return "OK\r\n";
                }
                if ($this->acceptHistoricalAttendanceQueryReply($device, $context)) {
                    $this->finishRawLog($rawLog, 'processed', 0, [
                        'mode' => 'legacy_attlog_backfill_reply',
                        'authenticated' => false,
                    ]);
                    return "OK\r\n";
                }

                throw new \Illuminate\Auth\Access\AuthorizationException('Device command callback authentication required');
            }

            $records = $this->extractCommandReplyRecords($context);
            if ($records === []) {
                if ($context['raw_body'] === '') {
                    $this->finishRawLog($rawLog, 'processed', 0, ['mode' => 'callback_ping']);
                    $this->logRequestHandled($context['endpoint'], $device, [
                        'mode' => 'callback_ping',
                        'record_count' => 0,
                    ]);

                    return "OK\r\n";
                }

                throw new \InvalidArgumentException('Malformed command callback payload');
            }

            $updated = 0;

            foreach ($records as $record) {
                $command = $this->commandService->resolveCommandForReply($device, $record);

                if (! $command) {
                    Log::warning('Received unmatched ZKTeco command callback', [
                        'device_id' => $device->id,
                        'serial_number' => $device->serial_number,
                        'record' => $record,
                    ]);

                    continue;
                }

                $this->commandService->recordDeviceReply($command, $record, $context['raw_body']);
                $updated++;
            }

            $this->finishRawLog($rawLog, 'processed', $updated, ['records' => $records]);
            $this->logRequestHandled($context['endpoint'], $device, ['record_count' => $updated]);

            return "OK\r\n";
        } catch (\Throwable $exception) {
            $this->finishRawLog($rawLog, 'failed', 0, $context['metadata'], $exception->getMessage());
            $this->recordError($rawLog?->device, 'api_error', $exception->getMessage(), 'high');

            throw $exception;
        }
    }

    public function handleTestPing(Request $request): string
    {
        $context = $this->buildContext($request, '/iclock/test');
        $rawLog = $this->createRawLog(null, $context);

        try {
            $device = $this->resolveDevice($request, $context);
            $this->attachRawLogDevice($rawLog, $device);
            $this->touchDevice($device, $request, $context);
            $this->finishRawLog($rawLog, 'processed', 0, ['mode' => 'test']);
            $this->logRequestHandled($context['endpoint'], $device, ['mode' => 'test']);

            return "OK\r\n";
        } catch (\Throwable $exception) {
            $this->finishRawLog($rawLog, 'failed', 0, $context['metadata'], $exception->getMessage());
            $this->recordError($rawLog?->device, 'api_error', $exception->getMessage(), 'medium');

            throw $exception;
        }
    }

    private function processCdataPayload(Device $device, array $context): array
    {
        if ($context['raw_body'] === '') {
            return $this->mergeDeviceMetadata($device, $context);
        }

        $attendancePayload = $this->payloadParser->parseAttendancePayload($context['raw_body']);
        if ($attendancePayload['records'] !== []) {
            return $this->storeAttendanceLogs($device, $context, $attendancePayload);
        }

        $acceptUserPayload = $this->shouldSyncUsersFromContext($context);
        $parsedUserPayload = $this->payloadParser->parseUserPayload($context['raw_body']);
        $userPayload = $acceptUserPayload
            ? $parsedUserPayload
            : ['records' => [], 'errors' => []];
        $biometricPayload = $this->payloadParser->parseBiometricPayload($context['raw_body']);

        if ($acceptUserPayload && $userPayload['records'] !== [] && $biometricPayload['records'] !== []) {
            $userResult = $this->syncUsers($device, $context, $userPayload);
            $biometricResult = $this->storeBiometrics($device, $context, $biometricPayload);
            $warnings = array_values(array_unique(array_merge($userResult['warnings'], $biometricResult['warnings'])));
            $count = $userResult['count'] + $biometricResult['count'];

            return [
                'type' => 'userinfo_biometric',
                'count' => $count,
                'warnings' => $warnings,
                'metadata' => [
                    'table' => $context['table'] ?? 'OPERLOG',
                    'components' => ['userinfo', 'biometric'],
                    'warnings' => $warnings,
                ],
                'response' => "OK:{$count}\r\n",
            ];
        }

        if ($acceptUserPayload && $userPayload['records'] !== []) {
            return $this->syncUsers($device, $context, $userPayload);
        }

        if ($biometricPayload['records'] !== []) {
            return $this->storeBiometrics($device, $context, $biometricPayload);
        }

        if (! $acceptUserPayload && $parsedUserPayload['records'] !== []) {
            return [
                'type' => 'ignored_userinfo',
                'count' => 0,
                'warnings' => ['Ignored employee payload from OPERLOG; only USERINFO uploads create employees.'],
                'metadata' => [
                    'table' => $context['table'] ?? 'OPERLOG',
                    'warnings' => ['ignored_operlog_user_payload'],
                ],
                'response' => "OK:0\r\n",
            ];
        }

        return $this->mergeDeviceMetadata($device, $context);
    }

    private function shouldSyncUsersFromContext(array $context): bool
    {
        $table = strtoupper(trim((string) ($context['table'] ?? '')));

        if ($table === '') {
            return true;
        }

        return $table === 'USERINFO';
    }

    private function buildContext(Request $request, string $endpoint): array
    {
        $rawBody = trim((string) $request->getContent());
        $query = $request->query();
        $bodyPairs = $this->payloadParser->parseMetadataPayload($rawBody);
        $metadata = array_filter(
            array_merge($query, $bodyPairs),
            static fn ($value): bool => $value !== null && $value !== ''
        );

        return [
            'endpoint' => $endpoint,
            'query' => $query,
            'body_pairs' => $bodyPairs,
            'raw_body' => $rawBody,
            'serial_number' => $query['SN'] ?? $query['sn'] ?? $bodyPairs['SN'] ?? $bodyPairs['sn'] ?? null,
            'table' => $query['table'] ?? $query['Table'] ?? $bodyPairs['table'] ?? $bodyPairs['Table'] ?? null,
            'stamp' => $query['Stamp'] ?? $bodyPairs['Stamp'] ?? null,
            'op_stamp' => $query['OpStamp'] ?? $bodyPairs['OpStamp'] ?? null,
            'metadata' => $metadata,
        ];
    }

    private function hasAuthenticatedDeviceToken(Request $request, Device $device): bool
    {
        $key = (string) config('zkteco.token_query_key', 'token');
        $provided = $request->query($key)
            ?: $request->header('X-Device-Token')
            ?: $request->bearerToken();

        return is_string($device->api_token)
            && $device->api_token !== ''
            && is_string($provided)
            && $provided !== ''
            && hash_equals($device->api_token, $provided);
    }

    private function resolveDevice(Request $request, array $context): Device
    {
        $this->assertTrustedRequest($request);

        $serialNumber = $this->validateSerialNumber($context['serial_number'] ?? null);

        $allowed = config('zkteco.allowed_serial_numbers', []);
        if ($allowed !== [] && ! in_array($serialNumber, $allowed, true)) {
            throw new AuthorizationException('Device serial is not allowed');
        }

        if ($this->isDeletedSerialBlocked($serialNumber)) {
            throw new AuthorizationException('Deleted device serial is blocked');
        }

        $device = Device::withTrashed()
            ->where(function ($query) use ($serialNumber): void {
                $query->where('serial_number', $serialNumber)
                    ->orWhere('sn', $serialNumber);
            })
            ->first();

        if ($device?->trashed()) {
            $device->restore();
            $device->refresh();

            Log::info('Restored soft-deleted ZKTeco device during ADMS handshake', [
                'device_id' => $device->id,
                'serial_number' => $serialNumber,
            ]);
        }

        if (! $device) {
            if (! config('zkteco.auto_register_devices', true)) {
                throw new AuthorizationException('Unknown device');
            }

            $endpoint = $this->resolveDeviceEndpoint($serialNumber, $request);

            $device = Device::query()->create([
                'name' => "ZKTeco {$serialNumber}",
                'serial_number' => $serialNumber,
                'sn' => $serialNumber,
                'status' => Device::STATUS_ACTIVE,
                'ip_address' => $endpoint['ip_address'],
                'port' => $endpoint['port'],
                'api_token' => Str::random(40),
                'raw_config' => $this->filterMetadata($context['metadata']),
            ]);
        }

        if (config('zkteco.require_token', false)) {
            $providedToken = $request->query(config('zkteco.token_query_key', 'token'))
                ?: $request->header('X-Device-Token')
                ?: $request->bearerToken();

            if (! $device->api_token || ! $providedToken || ! hash_equals($device->api_token, (string) $providedToken)) {
                throw new AuthorizationException('Invalid device token');
            }
        }

        return $device;
    }

    private function isDeletedSerialBlocked(string $serialNumber): bool
    {
        if (! Schema::hasTable('deleted_device_serials')) {
            return false;
        }

        return DB::table('deleted_device_serials')
            ->where('serial_number', $serialNumber)
            ->exists();
    }

    private function assertTrustedRequest(Request $request): void
    {
        $allowedIps = config('zkteco.allowed_ips', []);
        if ($allowedIps !== [] && ! in_array($request->ip(), $allowedIps, true)) {
            throw new AuthorizationException('Source IP is not allowed');
        }
    }

    private function validateSerialNumber(mixed $serialNumber): string
    {
        $serialNumber = trim((string) $serialNumber);

        if ($serialNumber === '') {
            throw new \InvalidArgumentException('Missing device serial number');
        }

        if (! preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $serialNumber)) {
            throw new \InvalidArgumentException('Invalid device serial number');
        }

        return $serialNumber;
    }

    private function touchDevice(Device $device, Request $request, array $context): void
    {
        $endpoint = $this->resolveDeviceEndpoint(
            $device->serial_number ?: $context['serial_number'],
            $request,
            $device
        );

        $device->forceFill([
            'serial_number' => $device->serial_number ?: $context['serial_number'],
            'sn' => $device->sn ?: $context['serial_number'],
            'ip_address' => $endpoint['ip_address'],
            'port' => $endpoint['port'],
            'status' => Device::STATUS_ACTIVE,
            'last_seen' => now(),
            'last_heartbeat' => now(),
            'raw_config' => array_merge($device->raw_config ?? [], $this->filterMetadata($context['metadata'])),
        ])->save();
    }

    private function resolveDeviceEndpoint(string $serialNumber, Request $request, ?Device $device = null): array
    {
        $configuredEndpoint = config("zkteco.device_endpoints.{$serialNumber}");
        $configuredIpAddress = $this->normalizeIpAddress($configuredEndpoint['ip_address'] ?? null);

        if ($configuredIpAddress !== null) {
            return [
                'ip_address' => $configuredIpAddress,
                'port' => $this->normalizePort($configuredEndpoint['port'] ?? null)
                    ?? $this->normalizePort($device?->port)
                    ?? (int) config('zkteco.default_device_port', 8000),
            ];
        }

        $existingIpAddress = $this->normalizeIpAddress($device?->ip_address);
        $requestIpAddress = $this->normalizeIpAddress($request->ip());

        if (
            $existingIpAddress === null
            || ($requestIpAddress !== null
                && $this->isLoopbackAddress($existingIpAddress)
                && ! $this->isLoopbackAddress($requestIpAddress))
        ) {
            $existingIpAddress = $requestIpAddress;
        }

        return [
            'ip_address' => $existingIpAddress ?? $request->ip(),
            'port' => $this->normalizePort($device?->port)
                ?? (int) config('zkteco.default_device_port', 8000),
        ];
    }

    private function normalizeIpAddress(mixed $ipAddress): ?string
    {
        $ipAddress = trim((string) $ipAddress);

        if ($ipAddress === '') {
            return null;
        }

        return filter_var($ipAddress, FILTER_VALIDATE_IP) !== false
            ? $ipAddress
            : null;
    }

    private function normalizePort(mixed $port): ?int
    {
        if ($port === null || $port === '') {
            return null;
        }

        $port = (int) $port;

        return $port >= 1 && $port <= 65535
            ? $port
            : null;
    }

    private function isLoopbackAddress(string $ipAddress): bool
    {
        return in_array($ipAddress, ['127.0.0.1', '::1'], true);
    }

    private function shouldRespondWithOptions(Request $request, array $context): bool
    {
        if (array_key_exists('options', $context['query']) || array_key_exists('option', $context['query'])) {
            return true;
        }

        if ($context['raw_body'] !== '') {
            return false;
        }

        if ($request->isMethod('GET')) {
            return true;
        }

        return $context['table'] === null && $context['stamp'] === null && $context['op_stamp'] === null;
    }

    private function buildOptionsResponse(Device $device): string
    {
        $attendanceStamp = $device->attendanceLogs()->max('id') ?? config('zkteco.option_stamp');
        $commandStamp = $device->commands()->max('id') ?? config('zkteco.option_op_stamp');

        $lines = [
            "GET OPTION FROM: {$device->serial_number}",
            "Stamp={$attendanceStamp}",
            "OpStamp={$commandStamp}",
            'ErrorDelay='.config('zkteco.option_error_delay'),
            'Delay='.config('zkteco.option_delay'),
            'ResLogDay='.config('zkteco.option_res_log_day'),
            'ResLogDelCount='.config('zkteco.option_res_log_del_count'),
            'ResLogCount='.config('zkteco.option_res_log_count'),
            'TransTimes='.config('zkteco.option_trans_times'),
            'TransInterval='.config('zkteco.option_trans_interval'),
            'TransFlag='.config('zkteco.option_trans_flag'),
            'TimeZone='.$this->resolveOptionTimezone(),
            'Realtime='.(config('zkteco.option_realtime') ? '1' : '0'),
            'Encrypt='.(config('zkteco.option_encrypt') ? '1' : '0'),
        ];

        // Optional PUSH firmware clock synchronization hint. The terminal
        // may ignore this, so verify the actual device clock before relying
        // on it; authenticated remote time commands remain separate.
        $syncInterval = (int) config('zkteco.option_sync_time_seconds', 0);
        if ($syncInterval > 0) {
            $lines[] = 'SyncTime='.$syncInterval;
        }

        return implode("\r\n", $lines)."\r\n";
    }

    private function storeAttendanceLogs(Device $device, array $context, ?array $parsed = null): array
    {
        $parsed ??= $this->payloadParser->parseAttendancePayload($context['raw_body']);
        if ($parsed['records'] === []) {
            throw new \InvalidArgumentException('Malformed attendance payload');
        }

        $warnings = $parsed['errors'];
        $preparedRecords = collect();
        $ignoredLegacyCodes = [];

        foreach ($parsed['records'] as $record) {
            $logTime = $this->payloadParser->normalizeTimestamp($record['timestamp'] ?? null);

            if (! $logTime) {
                $warnings[] = sprintf('Skipped attendance row with invalid timestamp for employee %s', $record['employee_code'] ?? 'unknown');

                continue;
            }

            $employeeCode = trim((string) ($record['employee_code'] ?? ''));
            if ($employeeCode === '') {
                $warnings[] = 'Skipped attendance row without employee code';

                continue;
            }

            if ($this->userSyncService->isLegacyOperationLogRecord(
                $employeeCode,
                $record['name'] ?? null,
                is_array($record['raw'] ?? null) ? $record['raw'] : [],
            )) {
                $ignoredLegacyCodes[] = strtoupper($employeeCode);
                $warnings[] = sprintf('Ignored legacy OPLOG attendance row for %s', $employeeCode);

                continue;
            }

            [$logType, $punchState] = $this->normalizePunchState($record['status'] ?? null);
            $preparedRecords->push([
                'employee_code' => $employeeCode,
                'uid' => $record['uid'] ?? null,
                'name' => $record['name'] ?? null,
                'card_no' => $record['card_no'] ?? null,
                'log_type' => $logType,
                'punch_state' => $punchState,
                'verify_type' => $this->normalizeVerifyType($record['verify'] ?? null),
                'work_code' => $record['work_code'] ?? null,
                'log_time' => $logTime,
                'device_log_id' => $record['device_log_id'] ?? null,
                'temperature' => $record['temperature'] ?? null,
                'raw_payload' => $record['raw'] ?? $record,
                'payload_hash' => $this->buildAttendancePayloadHash($device, $record, $logTime),
            ]);
        }

        if ($preparedRecords->isEmpty()) {
            if ($ignoredLegacyCodes !== []) {
                $this->userSyncService->purgeLegacyOperationLogArtifacts(array_values(array_unique($ignoredLegacyCodes)));
            }

            return [
                'type' => 'attendance',
                'count' => 0,
                'warnings' => array_values(array_unique($warnings)),
                'metadata' => [
                    'table' => 'ATTLOG',
                    'warnings' => $warnings,
                ],
                'response' => "OK:0\r\n",
            ];
        }

        // A PUSH device answers DATA QUERY ATTLOG with Stamp=0. Store
        // this retrospective batch in adms first so historic device-clock
        // offsets / unknown badges cannot poison dbo.Attendance or cause
        // full-batch retries. Ordinary live Stamp>0 remains writable.
        $queryStamp = trim((string) (
            $context['metadata']['Stamp']
            ?? $context['metadata']['stamp']
            ?? ''
        ));
        $stagingBackfill = config('zkteco.attlog_backfill_enabled', false)
            && (string) $device->serial_number === (string) config('zkteco.attlog_backfill_serial', '')
            && $queryStamp === '0';

        // Under explicit cutover, write first to the ORIGINAL SQL Server DB.
        // If that fails, return an error (not OK) to the device; a retry will
        // check and avoid duplicating the original SQL Server punch.
        // IMPORTANT: global cutover enabled does not mean every terminal
        // may INSERT into original dbo.Attendance. Non-approved serials are
        // accepted only into isolated adms.attendance_logs and ACKed after
        // successful staging. This avoids an endless 500/retry loop that was
        // observed for a terminal outside the Hili writer allowlist.
        $legacyWriter = app(LegacyAttendanceWriter::class);
        $eligibleOriginalWriter = ! $stagingBackfill
            && $legacyWriter->canWriteFor($device);

        if ($eligibleOriginalWriter) {
            $sourceRecords = $preparedRecords;
            // Hili's clock was four hours slow before the 2026-11-12
            // correction. Preserve pre-correction device history in adms
            // for reconciliation and avoid silently polluting original
            // dbo.Attendance with unverified, possibly shifted timestamps.
            $reviewBefore = (string) config('zkteco.attlog_backfill_review_before', '');
            $backfillSerial = (string) config('zkteco.attlog_backfill_serial', '');
            if ($reviewBefore !== '' && $backfillSerial !== ''
                && hash_equals($backfillSerial, (string) $device->serial_number)
                && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $reviewBefore)) {
                $timezone = (string) config('legacy_attendance.attendance_wallclock_timezone', 'Asia/Dubai');
                $sourceRecords = $preparedRecords->filter(static function (array $record) use ($reviewBefore, $timezone): bool {
                    return \Carbon\CarbonImmutable::instance($record['log_time'])
                        ->setTimezone($timezone)->format('Y-m-d H:i:s') >= $reviewBefore;
                })->values();
            }

            if ($sourceRecords->isNotEmpty()) {
                $legacyWriter->persist($device, $sourceRecords);
            }
        }

        $newHashes = [];

        DB::transaction(function () use ($device, $preparedRecords, &$newHashes): void {
            $existingEmployees = Employee::query()
                ->whereIn('employee_id', $preparedRecords->pluck('employee_code')->unique()->all())
                ->get()
                ->keyBy('employee_id');

            $employeeRows = $preparedRecords
                ->map(function (array $record) use ($device, $existingEmployees): array {
                    /** @var Employee|null $existingEmployee */
                    $existingEmployee = $existingEmployees->get($record['employee_code']);
                    $recordUid = trim((string) ($record['uid'] ?? ''));
                    $fallbackUid = preg_replace('/[^0-9]/', '', trim((string) $record['employee_code']));
                    $resolvedUid = $recordUid !== ''
                        ? $recordUid
                        : ($existingEmployee?->uid ?: $existingEmployee?->sync_uid ?: ($fallbackUid !== '' ? $fallbackUid : null));
                    $recordCardNo = trim((string) ($record['card_no'] ?? ''));
                    $resolvedCardNo = $recordCardNo !== '' ? $recordCardNo : $existingEmployee?->card_no;
                    $recordName = trim((string) ($record['name'] ?? ''));
                    $resolvedName = $recordName !== ''
                        ? $recordName
                        : ($existingEmployee?->name ?: $existingEmployee?->sync_name ?: "User {$record['employee_code']}");

                    return [
                        'employee_id' => $record['employee_code'],
                        'device_id' => $device->id,
                        'uid' => $resolvedUid,
                        'sync_uid' => $resolvedUid,
                        'card_no' => $resolvedCardNo,
                        'name' => $resolvedName,
                        'sync_name' => $resolvedName,
                        'status' => Employee::STATUS_ACTIVE,
                        'raw_payload' => json_encode($record['raw_payload'], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ];
                })
                ->unique('employee_id')
                ->values();

            if ($employeeRows->isNotEmpty()) {
                // Keep each MERGE below SQL Server's 2,100-parameter ceiling.
                foreach ($employeeRows->chunk(50) as $batch) {
                    Employee::query()->upsert(
                        $batch->all(),
                        ['employee_id'],
                        ['device_id', 'uid', 'sync_uid', 'card_no', 'name', 'sync_name', 'status', 'raw_payload', 'updated_at']
                    );
                }
            }

            $employeeMap = Employee::query()
                ->whereIn('employee_id', $preparedRecords->pluck('employee_code')->unique()->all())
                ->get()
                ->keyBy('employee_id');

            $existingHashes = AttendanceLog::query()
                ->whereIn('payload_hash', $preparedRecords->pluck('payload_hash')->all())
                ->pluck('payload_hash')
                ->flip();

            $rows = $preparedRecords
                // A single device may resend the same punch more than once
                // within the same POST. Keep only one row per fingerprint.
                ->unique('payload_hash')
                ->reject(fn (array $record) => $existingHashes->has($record['payload_hash']))
                ->map(function (array $record) use ($device, $employeeMap): array {
                    return [
                        'device_id' => $device->id,
                        'employee_id' => $employeeMap->get($record['employee_code'])?->id,
                        'employee_code' => $record['employee_code'],
                        'uid' => $record['uid'],
                        'log_type' => $record['log_type'],
                        'punch_state' => $record['punch_state'],
                        'verify_type' => $record['verify_type'],
                        'work_code' => $record['work_code'],
                        'log_time' => $record['log_time'],
                        'device_log_id' => $record['device_log_id'],
                        'payload_hash' => $record['payload_hash'],
                        'raw_payload' => json_encode($record['raw_payload'], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                        'temperature' => $record['temperature'],
                        'status' => 'processed',
                        'source' => 'adms',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                })
                ->values();

            if ($rows->isNotEmpty()) {
                // SQL Server's grammar throws for insertOrIgnore.
                // The filtered UNIQUE(payload_hash) index is the final
                // duplicate-protection guard across concurrent requests.
                // On an actual insert error the transaction rolls back
                // and the device will safely retry the batch.
                // 50 rows * ~16 columns stays well within SQL Server's
                // 2,100 bound-parameter ceiling for large device uploads.
                foreach ($rows->chunk(50) as $batch) {
                    DB::table('attendance_logs')->insert($batch->all());
                }
                $newHashes = $rows->pluck('payload_hash')->all();
            }
        });

        if ($ignoredLegacyCodes !== []) {
            $this->userSyncService->purgeLegacyOperationLogArtifacts(array_values(array_unique($ignoredLegacyCodes)));
        }

        $device->forceFill(['last_sync' => now()])->save();
        $this->markDatasetPullCommandDone($device, 'PULL_ATTENDANCE', sprintf('ATTLOG received via /iclock/cdata (%d records)', count($newHashes)));

        $insertedLogs = $newHashes === []
            ? collect()
            : AttendanceLog::query()->whereIn('payload_hash', $newHashes)->get();

        $this->dispatchAttendanceWebhook($device, $insertedLogs);

        return [
            'type' => 'attendance',
            'count' => count($newHashes),
            'warnings' => array_values(array_unique($warnings)),
            'metadata' => [
                'table' => 'ATTLOG',
                'warnings' => $warnings,
                'staging_backfill' => $stagingBackfill,
                'original_write_mode' => $eligibleOriginalWriter
                    ? 'approved_original_writer'
                    : 'adms_staging_only',
                'review_cutoff' => config('zkteco.attlog_backfill_review_before'),
            ],
            // ACK already-stored punches too, otherwise firmware may keep
            // retrying a perfectly valid duplicate batch forever.
            'response' => 'OK:'.$preparedRecords->count()."\r\n",
        ];
    }

    private function syncUsers(Device $device, array $context, ?array $parsed = null): array
    {
        $ingested = $this->userSyncService->ingestDeviceUsers($device, $context['raw_body'], $parsed);

        $device->forceFill(['last_sync' => now()])->save();
        $this->markDatasetPullCommandDone($device, 'PULL_EMPLOYEES', sprintf('USERINFO received via /iclock/cdata (%d records)', $ingested['count']));

        return [
            'type' => 'userinfo',
            'count' => $ingested['count'],
            'warnings' => $ingested['warnings'],
            'metadata' => [
                'table' => 'USERINFO',
                'warnings' => $ingested['warnings'],
            ],
            'response' => 'OK:'.$ingested['count']."\r\n",
        ];
    }

    private function storeBiometrics(Device $device, array $context, ?array $parsed = null): array
    {
        $parsed ??= $this->payloadParser->parseBiometricPayload($context['raw_body']);
        if ($parsed['records'] === []) {
            throw new \InvalidArgumentException('Malformed biometric payload');
        }

        $employeeMap = Employee::query()
            ->whereIn('employee_id', collect($parsed['records'])->pluck('employee_code')->unique()->all())
            ->get()
            ->keyBy('employee_id');

        $rows = collect($parsed['records'])
            ->map(function (array $record) use ($device, $employeeMap): array {
                $employeeCode = trim((string) ($record['employee_code'] ?? ''));
                $templateIndex = trim((string) ($record['template_index'] ?? ''));
                $fileName = trim((string) ($record['file_name'] ?? ''));
                $syncKey = sha1(json_encode([
                    'device_id' => $device->id,
                    'employee_code' => $employeeCode,
                    'biometric_type' => $record['biometric_type'] ?? null,
                    'template_index' => $templateIndex !== '' ? $templateIndex : null,
                    'file_name' => $fileName !== '' ? $fileName : null,
                ], JSON_THROW_ON_ERROR));

                $templateData = $record['template_data'] ?? null;
                $dataSize = $record['data_size'] ?? null;

                if ($dataSize === null && $templateData !== null) {
                    $dataSize = strlen((string) $templateData);
                }

                return [
                    'device_id' => $device->id,
                    'employee_id' => $employeeMap->get($employeeCode)?->id,
                    'employee_code' => $employeeCode,
                    'biometric_type' => $record['biometric_type'],
                    'template_index' => $templateIndex !== '' ? $templateIndex : null,
                    'file_name' => $fileName !== '' ? $fileName : null,
                    'is_valid' => $record['is_valid'],
                    'data_size' => $dataSize,
                    'template_data' => $templateData,
                    'sync_key' => $syncKey,
                    'raw_payload' => json_encode($record['raw'] ?? $record, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                    'updated_at' => now(),
                    'created_at' => now(),
                ];
            })
            ->filter(fn (array $row): bool => $row['employee_code'] !== '')
            ->unique('sync_key')
            ->values();

        if ($rows->isEmpty()) {
            throw new \InvalidArgumentException('No valid biometric records found');
        }

        $existingTemplateHashes = EmployeeBiometric::query()
            ->whereIn('sync_key', $rows->pluck('sync_key')->all())
            ->get()
            ->mapWithKeys(static fn (EmployeeBiometric $biometric): array => [
                $biometric->sync_key => sha1((string) ($biometric->template_data ?? '')),
            ]);

        EmployeeBiometric::query()->upsert(
            $rows->all(),
            ['sync_key'],
            [
                'employee_id',
                'employee_code',
                'biometric_type',
                'template_index',
                'file_name',
                'is_valid',
                'data_size',
                'template_data',
                'raw_payload',
                'updated_at',
            ]
        );

        $this->dispatchBiometricTemplateStoredEvents($device, $rows, $employeeMap, $existingTemplateHashes);

        $device->forceFill([
            'last_sync' => now(),
            'enrolled_fingerprints' => EmployeeBiometric::query()
                ->where('device_id', $device->id)
                ->where('biometric_type', EmployeeBiometric::TYPE_FINGERPRINT)
                ->count(),
            'enrolled_faces' => EmployeeBiometric::query()
                ->where('device_id', $device->id)
                ->where('biometric_type', EmployeeBiometric::TYPE_FACE)
                ->count(),
        ])->save();

        return [
            'type' => 'biometric',
            'count' => $rows->count(),
            'warnings' => $parsed['errors'],
            'metadata' => [
                'table' => $context['table'] ?? 'OPERLOG',
                'warnings' => $parsed['errors'],
            ],
            'response' => 'OK:'.$rows->count()."\r\n",
        ];
    }

    private function dispatchBiometricTemplateStoredEvents(
        Device $device,
        Collection $rows,
        Collection $employeeMap,
        Collection $existingTemplateHashes,
    ): void {
        $employeesToSync = $rows
            ->filter(function (array $row) use ($existingTemplateHashes): bool {
                if (! in_array($row['biometric_type'], [
                    EmployeeBiometric::TYPE_FINGERPRINT,
                    EmployeeBiometric::TYPE_FACE,
                ], true)) {
                    return false;
                }

                $templateHash = sha1((string) ($row['template_data'] ?? ''));

                return $existingTemplateHashes->get($row['sync_key']) !== $templateHash;
            })
            ->groupBy(static fn (array $row): string => (string) ($row['employee_code'] ?? ''))
            ->filter(static fn (Collection $group, string $employeeCode): bool => $employeeCode !== '');

        foreach ($employeesToSync as $employeeCode => $group) {
            $employee = $employeeMap->get($employeeCode)
                ?? Employee::query()->where('employee_id', $employeeCode)->first();

            if (! $employee) {
                continue;
            }

            event(new BiometricTemplateStored(
                $device,
                $employee,
                $group->pluck('sync_key')->filter()->values()->all(),
            ));
        }
    }

    private function mergeDeviceMetadata(Device $device, array $context): array
    {
        $metadata = $this->filterDeviceMetadata($context['metadata']);

        if ($context['raw_body'] !== '' && $metadata === []) {
            throw new \InvalidArgumentException('Malformed or unsupported device payload');
        }

        $device->forceFill([
            'raw_config' => array_merge($device->raw_config ?? [], $metadata),
            'firmware_version' => $metadata['FWVersion'] ?? $metadata['FirmwareVersion'] ?? $device->firmware_version,
            'hardware_version' => $metadata['DeviceName'] ?? $metadata['HardwareVersion'] ?? $device->hardware_version,
            'last_sync' => now(),
        ])->save();

        return [
            'type' => 'metadata',
            'count' => 0,
            'warnings' => [],
            'metadata' => [
                'table' => $context['table'] ?? 'DEVICE',
                'metadata' => $metadata,
            ],
            'response' => "OK\r\n",
        ];
    }

    private function findDispatchableCommand(Device $device): ?DeviceCommand
    {
        $pending = $device->commands()
            ->where('status', DeviceCommand::STATUS_PENDING)
            ->orderBy('created_at')
            ->first();

        if ($pending) {
            return $pending;
        }

        $maxRetries = (int) config('zkteco.command_max_retries', 3);
        $retryAfter = now()->subSeconds((int) config('zkteco.command_retry_after_seconds', 30));

        $device->commands()
            ->where('status', DeviceCommand::STATUS_SENT)
            ->where('retry_count', '>=', $maxRetries)
            ->update([
                'status' => DeviceCommand::STATUS_FAILED,
                'error_message' => 'Command retry limit exceeded',
            ]);

        return $device->commands()
            ->where('status', DeviceCommand::STATUS_SENT)
            ->whereNotNull('sent_at')
            ->where('sent_at', '<=', $retryAfter)
            ->where('retry_count', '<', $maxRetries)
            ->orderBy('sent_at')
            ->first();
    }

    private function canUseLegacyTimeOnly(Device $device): bool
    {
        if (! config('adms_pilot.enabled', false)
            || ! config('zkteco.command_require_token', true)
            || ! config('zkteco.legacy_time_only_enabled', false)
            || ! $device->exists || $device->trashed()) {
            return false;
        }

        $serial = trim((string) ($device->serial_number ?: $device->sn));
        if ($serial === '') {
            return false;
        }

        if (in_array($serial, config('zkteco.legacy_time_only_serials', []), true)) {
            return true;
        }

        // No '*' wildcard: use the original, read-only registry, not all
        // serial strings ever presented by a caller. Office UA300 is the
        // single explicitly provisioned nonhistorical test terminal.
        if (! config('zkteco.legacy_time_only_all_registered', false)) {
            return false;
        }

        if ($serial === 'BZD5261200843') {
            return true;
        }

        try {
            return Cache::store('file')->remember(
                'adms:clock-roster:'.hash('sha256', $serial),
                300,
                static fn (): bool => DB::connection('legacy_sqlsrv')
                    ->table('dbo.Device')
                    ->where('DeviceSerialNumber', $serial)
                    ->exists()
            );
        } catch (\Throwable $e) {
            Log::warning('Clock sync roster lookup failed; denying time command', [
                'serial' => $serial,
                'exception_class' => get_class($e),
            ]);
            return false;
        }
    }

    /**
     * Returns exactly a date/time command and no operational command from the
     * shared queue. Cache throttling prevents repeated clock changes on polls.
     */
    private function unverifiedLegacyClockCommand(Device $device): ?string
    {
        if (! $this->canUseLegacyTimeOnly($device)) {
            return null;
        }

        $serial = trim((string) ($device->serial_number ?: $device->sn));
        $key = 'adms:legacy-clock-only:'.hash('sha256', $serial);
        $cooldown = max(60, (int) config('zkteco.legacy_time_only_interval_seconds', 300));

        if (! Cache::store('file')->add($key, true, $cooldown)) {
            return null;
        }

        // Unix seconds fits the device's 32-bit command ID until year 2038.
        // This is an ID ONLY; DateTime uses ZKTeco's Appendix 5 encoding.
        $commandId = (string) now()->timestamp;
        Cache::store('file')->put($key.':reply-id', $commandId, $cooldown);

        return 'C:'.$commandId.':SET OPTIONS DateTime='
            .$this->systemClockService->encodeForPushCommand();
    }

    private function acceptTimeOnlyAcknowledgement(Device $device, array $context): bool
    {
        if (! $this->canUseLegacyTimeOnly($device)) {
            return false;
        }

        $serial = trim((string) ($device->serial_number ?: $device->sn));
        $key = 'adms:legacy-clock-only:'.hash('sha256', $serial).':reply-id';
        $expected = (string) Cache::store('file')->get($key, '');
        if ($expected === '') {
            return false;
        }

        $records = $this->extractCommandReplyRecords($context);
        foreach ($records as $record) {
            $id = trim((string) ($record['ID'] ?? $record['CmdID'] ?? ''));
            $command = strtoupper(trim((string) ($record['CMD'] ?? $record['Command'] ?? '')));
            if ($id !== '' && hash_equals($expected, $id)
                && in_array($command, ['SET OPTIONS', 'SET OPTION'], true)) {
                Cache::store('file')->forget($key);
                return true;
            }
        }

        return false;
    }

    /**
     * Scoped one-shot retroactive read from one original ZKTeco terminal.
     * A public-HTTP serial is NOT proof of device identity: this exception
     * only requests ATTLOG and never authorizes ordinary device commands.
     */
    private function unverifiedHistoricalAttendanceQuery(Device $device): ?string
    {
        if (! config('adms_pilot.enabled', false)
            || ! config('zkteco.attlog_backfill_enabled', false)
            || ! config('zkteco.command_require_token', true)
            || ! $device->exists || $device->trashed()) {
            return null;
        }

        $serial = trim((string) ($device->serial_number ?: $device->sn));
        if ($serial === '' || ! hash_equals(
            (string) config('zkteco.attlog_backfill_serial', ''),
            $serial
        )) {
            return null;
        }

        // Require original SQL Server registration, never unknown devices.
        if (! DB::connection('legacy_sqlsrv')->table('dbo.Device')
            ->where('DeviceSerialNumber', $serial)->exists()) {
            return null;
        }

        $start = (string) config('zkteco.attlog_backfill_start', '');
        $end = (string) config('zkteco.attlog_backfill_end', '');
        if ($end === '') {
            $end = $this->systemClockService->formatForDevice();
        }
        foreach ([$start, $end] as $value) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
                return null;
            }
            try {
                $date = \Carbon\CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $value, 'Asia/Dubai');
                if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
                    return null;
                }
            } catch (\Throwable) {
                return null;
            }
        }
        if ($start > $end) {
            return null;
        }

        // A fixed end date is needed for a stable one-off cache key.
        // An omitted end uses a fixed 23:59:59 of today's UAE date.
        if ((string) config('zkteco.attlog_backfill_end', '') === '') {
            $end = substr($end, 0, 10).' 23:59:59';
        }

        $key = 'adms:attendance-history-query:'.hash('sha256', $serial.'|'.$start.'|'.$end);
        $cache = Cache::store('file');
        if (! $cache->add($key.':issued', true, 14 * 86400)) {
            return null;
        }

        // A positive int <= 2^31-1, distinct from hourly clock command IDs.
        $commandId = (string) (now()->timestamp - 1000000000);
        $cache->put($key.':reply-id', $commandId, 14 * 86400);

        return 'C:'.$commandId.':DATA QUERY ATTLOG StartTime='
            .$start."\t".'EndTime='.$end;
    }

    private function acceptHistoricalAttendanceQueryReply(Device $device, array $context): bool
    {
        if (! config('zkteco.attlog_backfill_enabled', false)
            || ! config('zkteco.command_require_token', true)
            || trim((string) ($device->serial_number ?: $device->sn))
                !== (string) config('zkteco.attlog_backfill_serial', '')) {
            return false;
        }

        $start = (string) config('zkteco.attlog_backfill_start', '');
        $end = (string) config('zkteco.attlog_backfill_end', '');
        if ($end === '') {
            $end = $this->systemClockService->now()->format('Y-m-d').' 23:59:59';
        }

        $serial = trim((string) ($device->serial_number ?: $device->sn));
        $key = 'adms:attendance-history-query:'.hash('sha256', $serial.'|'.$start.'|'.$end);
        $expected = (string) Cache::store('file')->get($key.':reply-id', '');
        if ($expected === '') {
            return false;
        }

        foreach ($this->extractCommandReplyRecords($context) as $record) {
            $id = trim((string) ($record['ID'] ?? $record['CmdID'] ?? $record['id'] ?? ''));
            $command = strtoupper(trim((string) ($record['CMD'] ?? $record['Command'] ?? '')));
            if ($id !== '' && hash_equals($expected, $id) && $command === 'DATA') {
                // Return may be a negative error code. Record the exact
                // reply status for manual diagnostics; never delete any logs.
                Cache::store('file')->put($key.':last-response', $record, 14 * 86400);
                return true;
            }
        }

        return false;
    }

    private function ensureAutomaticTimeSyncQueued(Device $device): void
    {
        if (! config('zkteco.auto_time_sync_enabled', false)) {
            return;
        }

        $interval = max(1, (int) config('zkteco.auto_time_sync_interval_hours', 24));
        $cutoff = now()->subHours($interval);

        // Lock the device row to avoid creating duplicate time commands
        // when concurrent polls arrive during the same second.
        DB::transaction(function () use ($device, $cutoff): void {
            Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();

            $alreadyQueued = $device->commands()
                ->whereIn('command', ['SET_TIMEZONE', 'UPDATE_TIME'])
                ->where('created_at', '>=', $cutoff)
                ->exists();

            if ($alreadyQueued) {
                return;
            }

            $this->deviceService->queueDeviceCommand(
                $device->id,
                'SET_TIMEZONE',
                [
                    'option' => 'TimeZone',
                    'value' => $this->systemClockService->deviceTimezoneOption(),
                ],
                'Automatic authenticated device timezone sync'
            );
            $this->deviceService->queueDeviceCommand(
                $device->id,
                'UPDATE_TIME',
                ['sync_on_dispatch' => true],
                'Automatic authenticated device date/time sync'
            );
        });
    }

    private function ensureAutomaticEmployeePullQueued(Device $device): void
    {
        if (! config('zkteco.auto_pull_employees_on_contact', true)) {
            return;
        }

        if ($device->employees()->exists()) {
            return;
        }

        $cooldownStartedAt = now()->subMinutes((int) config('zkteco.auto_pull_employees_cooldown_minutes', 15));

        $recentUserSync = $device->rawLogs()
            ->where('endpoint', '/iclock/cdata')
            ->where('processing_status', 'processed')
            ->where('created_at', '>=', $cooldownStartedAt)
            ->where('parsed_data->type', 'userinfo')
            ->exists();

        if ($recentUserSync) {
            return;
        }

        $this->deviceService->ensurePullEmployeesQueued(
            $device->id,
            notes: 'Auto-pull employees from device'
        );
    }

    private function markDatasetPullCommandDone(Device $device, string $command, string $message): void
    {
        $queryCommand = $device->commands()
            ->where('command', $command)
            ->whereIn('status', [
                DeviceCommand::STATUS_PENDING,
                DeviceCommand::STATUS_SENT,
            ])
            ->latest('created_at')
            ->first();

        if (! $queryCommand) {
            return;
        }

        $queryCommand->forceFill([
            'status' => DeviceCommand::STATUS_DONE,
            'response' => $message,
            'response_payload' => [
                'source' => 'cdata',
                'message' => $message,
            ],
            'executed_at' => now(),
            'acknowledged_at' => now(),
            'error_message' => null,
        ])->save();
    }

    private function markCommandAsDispatched(DeviceCommand $command, string $payload): void
    {
        $retryCount = $command->status === DeviceCommand::STATUS_SENT
            ? $command->retry_count + 1
            : $command->retry_count;

        $command->forceFill([
            'status' => DeviceCommand::STATUS_SENT,
            'sent_at' => now(),
            'retry_count' => $retryCount,
            'command_payload' => $payload,
            'error_message' => null,
        ])->save();
    }

    private function resolveCommandForReply(Device $device, array $record): ?DeviceCommand
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
                $payload = strtoupper($candidate->command_payload ?: $this->commandFormatter->format($candidate));

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
                    $payload = strtoupper($candidate->command_payload ?: $this->commandFormatter->format($candidate));

                    return str_contains($payload, $replyCommand) || str_contains(strtoupper($candidate->command), $replyCommand);
                });
        }

        return $sentCandidates->count() === 1 ? $sentCandidates->first() : null;
    }

    private function extractCommandReplyRecords(array $context): array
    {
        $records = $this->payloadParser->parseCommandReplyPayload($context['raw_body']);
        if ($records !== []) {
            return $records;
        }

        $queryRecord = collect($context['metadata'])
            ->except([
                'SN',
                'sn',
                'table',
                'Table',
                'Stamp',
                'OpStamp',
                'token',
                'api_token',
                'options',
                'option',
            ])
            ->all();

        return $this->containsCommandReplyFields($queryRecord) ? [$queryRecord] : [];
    }

    private function containsCommandReplyFields(array $record): bool
    {
        return collect(array_keys($record))
            ->contains(static fn (string $key): bool => in_array($key, [
                'ID',
                'CmdID',
                'id',
                'Return',
                'CMD',
                'Command',
                'Message',
                'Error',
            ], true));
    }

    private function buildAttendancePayloadHash(Device $device, array $record, $logTime): string
    {
        return sha1(implode('|', [
            $device->id,
            (string) ($record['employee_code'] ?? ''),
            (string) ($record['uid'] ?? ''),
            $logTime?->format('Y-m-d H:i:s') ?? '',
            (string) ($record['status'] ?? ''),
            (string) ($record['verify'] ?? ''),
            (string) ($record['device_log_id'] ?? ''),
            (string) ($record['work_code'] ?? ''),
        ]));
    }

    private function normalizePunchState(?string $value): array
    {
        return match ((string) $value) {
            '0' => ['checkin', 'checkin'],
            '1' => ['checkout', 'checkout'],
            '2', '3' => ['break', 'break'],
            '4', '5' => ['overtime', 'overtime'],
            default => ['unknown', $value ? "state_{$value}" : 'unknown'],
        };
    }

    private function normalizeVerifyType(null|string|int $value): string
    {
        return match (strtolower((string) $value)) {
            '1', 'fingerprint', 'fp' => 'fingerprint',
            '2', '11', 'rfid', 'card' => 'rfid',
            '3', '4', '15', '17', 'face' => 'face',
            '5', 'palm' => 'palm',
            '0', 'password', 'pwd' => 'password',
            default => 'unknown',
        };
    }

    private function isSuccessfulCommandReply(mixed $returnCode): bool
    {
        return in_array(strtoupper(trim((string) $returnCode)), ['0', '1', 'OK', 'SUCCESS', 'TRUE'], true);
    }

    private function filterMetadata(array $metadata): array
    {
        return collect($metadata)
            ->reject(static function ($value, $key): bool {
                return in_array(strtolower((string) $key), ['token', 'api_token'], true);
            })
            ->map(static fn ($value) => is_string($value) ? trim($value) : $value)
            ->filter(static fn ($value) => $value !== null && $value !== '')
            ->all();
    }

    private function filterDeviceMetadata(array $metadata): array
    {
        return collect($this->filterMetadata($metadata))
            ->reject(static function ($value, $key): bool {
                return in_array(strtolower((string) $key), [
                    'sn',
                    'table',
                    'stamp',
                    'opstamp',
                    'options',
                    'option',
                ], true);
            })
            ->all();
    }

    private function resolveOptionTimezone(): string
    {
        $configured = trim((string) config('zkteco.option_timezone', ''));

        if (preg_match('/^([+-])(\d{1,2}):(\d{2})$/', $configured, $parts)) {
            $hours = (int) $parts[2];
            $minutes = (int) $parts[3];

            if ($hours <= 14 && $minutes < 60) {
                return sprintf('%s%02d:%02d', $parts[1], $hours, $minutes);
            }
        }

        // Backwards compatible with previously deployed TimeZone=4 setting.
        // The ZKTeco PUSH protocol expects TimeZone=+04:00 instead of "4".
        if (preg_match('/^([+-]?)(\d{1,2})$/', $configured, $parts)) {
            $hours = (int) $parts[2];
            if ($hours <= 14) {
                return sprintf('%s%02d:00', $parts[1] === '-' ? '-' : '+', $hours);
            }
        }

        return $this->systemClockService->deviceTimezoneOption();
    }

    private function dispatchAttendanceWebhook(Device $device, Collection $logs): void
    {
        $url = config('zkteco.webhook.url');
        if (! $url || $logs->isEmpty()) {
            return;
        }

        $payload = [
            'event' => 'attendance.received',
            'device' => [
                'id' => $device->id,
                'serial_number' => $device->serial_number,
                'name' => $device->name,
            ],
            'records' => $logs->map(static function (AttendanceLog $log): array {
                return [
                    'id' => $log->id,
                    'employee_id' => $log->employee_id,
                    'employee_code' => $log->employee_code,
                    'uid' => $log->uid,
                    'type' => $log->log_type,
                    'verify_type' => $log->verify_type,
                    'timestamp' => $log->log_time?->toIso8601String(),
                ];
            })->values()->all(),
            'sent_at' => now()->toIso8601String(),
        ];

        $request = Http::timeout((int) config('zkteco.webhook.timeout', 5))->acceptJson();

        $secret = config('zkteco.webhook.secret');
        if ($secret) {
            $request = $request->withHeaders([
                'X-ZKTeco-Signature' => hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $secret),
            ]);
        }

        try {
            $request->post($url, $payload)->throw();
        } catch (\Throwable $exception) {
            Log::warning('Attendance webhook delivery failed', [
                'device_id' => $device->id,
                'url' => $url,
                'message' => $exception->getMessage(),
            ]);

            $this->recordError($device, 'api_error', 'Attendance webhook delivery failed', 'medium');
        }
    }

    private function createRawLog(?Device $device, array $context): RawLog
    {
        return RawLog::query()->create([
            'device_id' => $device?->id,
            'endpoint' => $context['endpoint'],
            'payload' => $context['raw_body'],
            'parsed_data' => $this->filterMetadata($context['metadata']),
            'processing_status' => 'processing',
            'record_count' => 0,
        ]);
    }

    private function attachRawLogDevice(RawLog $rawLog, Device $device): void
    {
        if ($rawLog->device_id !== $device->id) {
            $rawLog->forceFill(['device_id' => $device->id])->save();
        }
    }

    private function finishRawLog(RawLog $rawLog, string $status, int $recordCount, array $parsedData = [], ?string $errorMessage = null): void
    {
        $rawLog->forceFill([
            'processing_status' => $status,
            'record_count' => $recordCount,
            'parsed_data' => $this->filterMetadata($parsedData),
            'error_message' => $this->truncateText($errorMessage, 60000),
        ])->save();
    }

    private function recordError(?Device $device, string $type, string $message, string $severity = 'medium'): void
    {
        ErrorLog::query()->create([
            'device_id' => $device?->id,
            'type' => $type,
            'message' => $this->truncateText($message, 60000),
            'severity' => $severity,
            'resolved' => false,
        ]);
    }

    private function logRequestHandled(string $endpoint, Device $device, array $context = []): void
    {
        Log::info('ZKTeco request handled', array_merge([
            'endpoint' => $endpoint,
            'device_id' => $device->id,
            'serial_number' => $device->serial_number,
        ], $context));
    }

    private function buildCommandResponseText(array $record, string $fallback): string
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

    private function truncateText(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strlen($value) > $maxLength
            ? mb_substr($value, 0, $maxLength)
            : $value;
    }
}
