<?php

return [
    'enabled' => filter_var(env('ADMS_PILOT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    // An empty serial list denies every device even when enabled.
    'serials' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMS_PILOT_SERIALS', ''))))),
    // Opt in to allow the complete original dbo.Device serial roster.
    // This is device eligibility, NOT identity/authentication.
    'allow_historical' => filter_var(env('ADMS_ALLOW_HISTORICAL_DEVICES', false), FILTER_VALIDATE_BOOLEAN),
    // Empty list means arbitrary source networks are allowed (not authenticated).
    'ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMS_PILOT_ALLOWED_IPS', ''))))),
];
