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
    // OFF by default. Enables verified original dbo.Device serials only; does
    // NOT include the office UA300, and must not be enabled until live pilot
    // write, UTC+4 wall-clock and public HTTP identity risks are reviewed.
    'attendance_write_all_registered' => filter_var(
        env('LEGACY_SQLSRV_ATTENDANCE_WRITE_ALL_REGISTERED', false),
        FILTER_VALIDATE_BOOLEAN
    ),
    'attendance_wallclock_timezone' => env('LEGACY_SQLSRV_ATTENDANCE_TIMEZONE', ''),
];
