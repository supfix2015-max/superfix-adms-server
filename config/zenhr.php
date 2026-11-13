<?php

// ZenHR is isolated to the AT2 staging/transition deployment.
// Do NOT enable the legacy AT1 host or attendance sync by default.
return [
    'enabled' => filter_var(env('ZENHR_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'host' => 'at2.superfix.ae',
    'client_id' => env('ZENHR_CLIENT_ID', ''),
    'client_secret' => env('ZENHR_CLIENT_SECRET', ''),
    'app_name' => env('ZENHR_APP_NAME', 'Superfix ADMS Enterprise'),
    'redirect_uri' => env('ZENHR_REDIRECT_URI', 'https://at2.superfix.ae/admin/integrations/zenhr/callback'),
    'authorize_url' => 'https://app.zenhr.com/oauth/authorize',
    'token_url' => 'https://app.zenhr.com/oauth/token',
    'api_base' => 'https://app.zenhr.com/api/v3',
    'scopes' => [
        'read:employee',
        'read:branch',
        'read:attendance_record',
        'create:attendance_detail',
        'bulk_insert:attendance_detail',
    ],
    // Selected ZenHR company branch. Never infer from physical device/office location.
    // Deliberately unset until the account's branch ID has been verified.
    'attendance_branch_id' => (int) env('ZENHR_ATTENDANCE_BRANCH_ID', 0),
    'outbox_cutoff_local' => '2026-11-12 00:00:00',
    'outbox_auto_collect' => filter_var(env('ZENHR_OUTBOX_AUTO_COLLECT', false), FILTER_VALIDATE_BOOLEAN),
    // Original BioWeb CustomExportService uses aiapi.zenhr.com for bulk POST,
    // whereas employee GETs and OAuth use app.zenhr.com.
    'attendance_bulk_url' => 'https://aiapi.zenhr.com/api/v3/branches/17787/attendance_details/bulk_insert',
    // Match the old BioWeb MySettings.AttendanceId. Must be explicitly set.
    'attendance_transaction_id' => (int) env('ZENHR_ATTENDANCE_TRANSACTION_ID', 0),
    // Fail closed: interactive --apply AND this server-side opt-in required.
    'attendance_post_enabled' => filter_var(env('ZENHR_ATTENDANCE_POST_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    // Independent opt-in for supervised automatic delivery. Never starts
    // merely because manual --apply is enabled.
    'attendance_auto_send' => filter_var(env('ZENHR_ATTENDANCE_AUTO_SEND', false), FILTER_VALIDATE_BOOLEAN),
    'attendance_sync_enabled' => false, // Old legacy scheduled sender remains off.
];
