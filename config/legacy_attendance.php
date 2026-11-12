<?php

return [
    // Disabled unless explicitly enabled on a separate test deployment.
    // The SQL Server login must be SELECT-only.
    'browser_enabled' => filter_var(env('LEGACY_SQLSRV_BROWSER_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    // Direct SQL Server attendance writes are disabled until manual preflight + backup.
    'attendance_write_enabled' => filter_var(env('LEGACY_SQLSRV_ATTENDANCE_WRITE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'attendance_write_serials' => array_values(array_filter(array_map(
        'trim', explode(',', (string) env('LEGACY_SQLSRV_ATTENDANCE_WRITE_SERIALS', ''))
    ))),
    'attendance_wallclock_timezone' => env('LEGACY_SQLSRV_ATTENDANCE_TIMEZONE', ''),
];
