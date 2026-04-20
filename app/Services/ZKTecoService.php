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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    public function handleGetRequest(Request $request): string
    {
        $context = $this->buildContext($request, '/iclock/getrequest');
        $rawLog = $this->createRawLog(null, $context);

        try {
            $device = $this->resolveDevice($request, $context);
            $this->attachRawLogDevice($rawLog, $device);
            $this->touchDevice($device, $request, $context);
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

        $userPayload = $this->payloadParser->parseUserPayload($context['raw_body']);
        $biometricPayload = $this->payloadParser->parseBiometricPayload($context['raw_body']);

        if ($userPayload['records'] !== [] && $biometricPayload['records'] !== []) {
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

        if ($userPayload['records'] !== []) {
            return $this->syncUsers($device, $context, $userPayload);
        }

        if ($biometricPayload['records'] !== []) {
            return $this->storeBiometrics($device, $context, $biometricPayload);
        }

        return $this->mergeDeviceMetadata($device, $context);
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

    private function resolveDevice(Request $request, array $context): Device
    {
        $this->assertTrustedRequest($request);

        $serialNumber = $this->validateSerialNumber($context['serial_number'] ?? null);

        $allowed = config('zkteco.allowed_serial_numbers', []);
        if ($allowed !== [] && ! in_array($serialNumber, $allowed, true)) {
            throw new AuthorizationException('Device serial is not allowed');
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
            throw new \InvalidArgumentException('No valid attendance records found');
        }

        $newHashes = [];

        DB::transaction(function () use ($device, $preparedRecords, &$newHashes): void {
            $employeeRows = $preparedRecords
                ->map(fn (array $record): array => [
                    'employee_id' => $record['employee_code'],
                    'device_id' => $device->id,
                    'uid' => $record['uid'],
                    'card_no' => $record['card_no'],
                    'name' => $record['name'] ?: "User {$record['employee_code']}",
                    'status' => 'active',
                    'raw_payload' => json_encode($record['raw_payload'], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                    'updated_at' => now(),
                    'created_at' => now(),
                ])
                ->unique('employee_id')
                ->values();

            if ($employeeRows->isNotEmpty()) {
                Employee::query()->upsert(
                    $employeeRows->all(),
                    ['employee_id'],
                    ['device_id', 'uid', 'card_no', 'name', 'status', 'raw_payload', 'updated_at']
                );
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
                DB::table('attendance_logs')->insertOrIgnore($rows->all());
                $newHashes = $rows->pluck('payload_hash')->all();
            }
        });

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
            ],
            'response' => 'OK:'.count($newHashes)."\r\n",
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
        $configured = config('zkteco.option_timezone');

        if ($configured !== null && trim((string) $configured) !== '') {
            return trim((string) $configured);
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
