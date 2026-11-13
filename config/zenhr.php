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
    'attendance_sync_enabled' => false, // Requires separate reviewed phase.
];
