<?php

$allowedSerials = array_filter(array_map(
    static fn (string $serial): string => trim($serial),
    explode(',', (string) env('ZKTECO_ALLOWED_SERIALS', ''))
));

$allowedIps = array_filter(array_map(
    static fn (string $ip): string => trim($ip),
    explode(',', (string) env('ZKTECO_ALLOWED_IPS', ''))
));

$deviceEndpoints = [];

foreach (preg_split('/[,\r\n]+/', (string) env('ZKTECO_DEVICE_ENDPOINTS', '')) as $mapping) {
    $mapping = trim($mapping);

    if ($mapping === '' || ! str_contains($mapping, '=')) {
        continue;
    }

    [$serialNumber, $endpoint] = array_map('trim', explode('=', $mapping, 2));

    if ($serialNumber === '' || $endpoint === '') {
        continue;
    }

    $ipAddress = $endpoint;
    $port = null;

    if (str_contains($endpoint, ':')) {
        $separator = strrpos($endpoint, ':');
        $candidatePort = substr($endpoint, $separator + 1);

        if ($candidatePort !== false && ctype_digit($candidatePort)) {
            $ipAddress = substr($endpoint, 0, $separator);
            $port = (int) $candidatePort;
        }
    }

    if (filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
        continue;
    }

    $deviceEndpoints[$serialNumber] = array_filter([
        'ip_address' => $ipAddress,
        'port' => $port,
    ], static fn ($value): bool => $value !== null);
}

return [
    'auto_register_devices' => env('ZKTECO_AUTO_REGISTER_DEVICES', false),
    'require_token' => env('ZKTECO_REQUIRE_TOKEN', true),
    'token_query_key' => env('ZKTECO_TOKEN_QUERY_KEY', 'token'),
    'allowed_serial_numbers' => array_values($allowedSerials),
    'allowed_ips' => array_values($allowedIps),
    'device_endpoints' => $deviceEndpoints,
    'default_device_port' => (int) env('ZKTECO_DEFAULT_DEVICE_PORT', 8000),

    'heartbeat_ttl_minutes' => (int) env('ZKTECO_HEARTBEAT_TTL_MINUTES', 5),
    'command_retry_after_seconds' => (int) env('ZKTECO_COMMAND_RETRY_AFTER_SECONDS', 30),
    'command_max_retries' => (int) env('ZKTECO_COMMAND_MAX_RETRIES', 3),
    'dispatch_commands' => env('ZKTECO_DISPATCH_COMMANDS', ! in_array(env('APP_ENV', 'production'), ['staging', 'testing'], true)),
    // Require per-device token before sending commands/reconciling callbacks,
    // even when a legacy terminal is temporarily allowed to upload attendance
    // by historical serial alone (HTTP is unencrypted).
    'command_require_token' => filter_var(env('ZKTECO_COMMAND_REQUIRE_TOKEN', true), FILTER_VALIDATE_BOOLEAN),
    // Automatic SET OPTION TimeZone / DateTime requires authenticated
    // /iclock/getrequest polls; never available for serial-only HTTP clients.
    'auto_time_sync_enabled' => filter_var(env('ZKTECO_AUTO_TIME_SYNC_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'auto_time_sync_interval_hours' => max(1, (int) env('ZKTECO_AUTO_TIME_SYNC_INTERVAL_HOURS', 24)),
    // Time-only exception for pre-token ADMS devices on HTTP.
    // Opt in to specific pilot serials. NEVER grants access to queued
    // biometric, reboot, deletion, enrollment or other commands.
    'legacy_time_only_enabled' => filter_var(env('ZKTECO_LEGACY_TIME_ONLY_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    // Opt-in all existing original dbo.Device serials plus the registered
    // office UA300, rather than a manually maintained comma-separated list.
    // This is NOT a blanket authorization for unknown devices.
    'legacy_time_only_all_registered' => filter_var(env('ZKTECO_LEGACY_TIME_ONLY_ALL_REGISTERED', false), FILTER_VALIDATE_BOOLEAN),
    'legacy_time_only_serials' => array_values(array_filter(array_map('trim', explode(',', (string) env('ZKTECO_LEGACY_TIME_ONLY_SERIALS', ''))))),
    'legacy_time_only_interval_seconds' => max(60, (int) env('ZKTECO_LEGACY_TIME_ONLY_INTERVAL_SECONDS', 300)),

    'auto_pull_employees_on_contact' => env('ZKTECO_AUTO_PULL_EMPLOYEES_ON_CONTACT', false),
    'auto_pull_employees_cooldown_minutes' => (int) env('ZKTECO_AUTO_PULL_EMPLOYEES_COOLDOWN_MINUTES', 15),
    'auto_cleanup_legacy_users' => env('ZKTECO_AUTO_CLEANUP_LEGACY_USERS', false),
    'system_timezone' => env('ZKTECO_SYSTEM_TIMEZONE'),
    'device_timezone' => env('ZKTECO_DEVICE_TIMEZONE', env('APP_TIMEZONE', 'UTC')),
    'option_timezone' => env('ZKTECO_OPTION_TIMEZONE'),
    // Some ZKTeco PUSH firmwares honor SyncTime during options handshake;
    // set to 3600 for hourly clock sync only after testing device firmware.
    'option_sync_time_seconds' => max(0, (int) env('ZKTECO_OPTION_SYNC_TIME_SECONDS', 0)),
    'option_stamp' => (string) env('ZKTECO_OPTION_STAMP', '9999'),
    'option_op_stamp' => (string) env('ZKTECO_OPTION_OPSTAMP', '9999'),
    'option_error_delay' => (string) env('ZKTECO_OPTION_ERROR_DELAY', '60'),
    'option_delay' => (string) env('ZKTECO_OPTION_DELAY', '30'),
    'option_res_log_day' => (string) env('ZKTECO_OPTION_RES_LOG_DAY', '18250'),
    'option_res_log_del_count' => (string) env('ZKTECO_OPTION_RES_LOG_DEL_COUNT', '10000'),
    'option_res_log_count' => (string) env('ZKTECO_OPTION_RES_LOG_COUNT', '50000'),
    'option_trans_times' => env('ZKTECO_OPTION_TRANS_TIMES', '00:00;14:05'),
    'option_trans_interval' => (string) env('ZKTECO_OPTION_TRANS_INTERVAL', '1'),
    'option_trans_flag' => env('ZKTECO_OPTION_TRANS_FLAG', 'TransData AttLog OpLog AttPhoto EnrollUser ChgUser EnrollFP ChgFP FPImag FACE UserPic'),
    'option_realtime' => env('ZKTECO_OPTION_REALTIME', true),
    'option_encrypt' => env('ZKTECO_OPTION_ENCRYPT', false),
    'face_enrollment_type' => (int) env('ZKTECO_FACE_ENROLLMENT_TYPE', 2),
    'delete_employee_commands' => array_values(array_filter([
        env('ZKTECO_DELETE_EMPLOYEE_USERINFO_COMMAND', 'DATA DELETE USERINFO PIN={pin}'),
        env('ZKTECO_DELETE_EMPLOYEE_FINGERPRINT_COMMAND', 'DATA DELETE FINGERTMP PIN={pin}'),
        env('ZKTECO_DELETE_EMPLOYEE_FACE_COMMAND', 'DATA DELETE FACE PIN={pin}'),
        env('ZKTECO_DELETE_EMPLOYEE_PHOTO_COMMAND', 'DATA DELETE USERPIC PIN={pin}'),
    ], static fn ($value): bool => is_string($value) && trim($value) !== '')),

    'webhook' => [
        'url' => env('ZKTECO_WEBHOOK_URL'),
        'secret' => env('ZKTECO_WEBHOOK_SECRET'),
        'timeout' => (int) env('ZKTECO_WEBHOOK_TIMEOUT', 5),
    ],
];
