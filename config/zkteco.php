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
    'auto_register_devices' => env('ZKTECO_AUTO_REGISTER_DEVICES', true),
    'require_token' => env('ZKTECO_REQUIRE_TOKEN', false),
    'token_query_key' => env('ZKTECO_TOKEN_QUERY_KEY', 'token'),
    'allowed_serial_numbers' => array_values($allowedSerials),
    'allowed_ips' => array_values($allowedIps),
    'device_endpoints' => $deviceEndpoints,
    'default_device_port' => (int) env('ZKTECO_DEFAULT_DEVICE_PORT', 8000),

    'heartbeat_ttl_minutes' => (int) env('ZKTECO_HEARTBEAT_TTL_MINUTES', 5),
    'command_retry_after_seconds' => (int) env('ZKTECO_COMMAND_RETRY_AFTER_SECONDS', 30),
    'command_max_retries' => (int) env('ZKTECO_COMMAND_MAX_RETRIES', 3),
    'auto_pull_employees_on_contact' => env('ZKTECO_AUTO_PULL_EMPLOYEES_ON_CONTACT', true),
    'auto_pull_employees_cooldown_minutes' => (int) env('ZKTECO_AUTO_PULL_EMPLOYEES_COOLDOWN_MINUTES', 15),
    'device_timezone' => env('ZKTECO_DEVICE_TIMEZONE', env('APP_TIMEZONE', 'UTC')),
    'option_timezone' => env('ZKTECO_OPTION_TIMEZONE', '0'),
    'option_stamp' => (string) env('ZKTECO_OPTION_STAMP', '9999'),
    'option_op_stamp' => (string) env('ZKTECO_OPTION_OPSTAMP', '9999'),
    'option_error_delay' => (string) env('ZKTECO_OPTION_ERROR_DELAY', '60'),
    'option_delay' => (string) env('ZKTECO_OPTION_DELAY', '30'),
    'option_res_log_day' => (string) env('ZKTECO_OPTION_RES_LOG_DAY', '18250'),
    'option_res_log_del_count' => (string) env('ZKTECO_OPTION_RES_LOG_DEL_COUNT', '10000'),
    'option_res_log_count' => (string) env('ZKTECO_OPTION_RES_LOG_COUNT', '50000'),
    'option_trans_times' => env('ZKTECO_OPTION_TRANS_TIMES', '00:00;14:05'),
    'option_trans_interval' => (string) env('ZKTECO_OPTION_TRANS_INTERVAL', '1'),
    'option_trans_flag' => env('ZKTECO_OPTION_TRANS_FLAG', '1111000000'),
    'option_realtime' => env('ZKTECO_OPTION_REALTIME', true),
    'option_encrypt' => env('ZKTECO_OPTION_ENCRYPT', false),

    'webhook' => [
        'url' => env('ZKTECO_WEBHOOK_URL'),
        'secret' => env('ZKTECO_WEBHOOK_SECRET'),
        'timeout' => (int) env('ZKTECO_WEBHOOK_TIMEOUT', 5),
    ],
];
