<?php

return [
    'enabled' => filter_var(env('ADMS_PILOT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    // An empty serial list denies every device even when enabled.
    'serials' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMS_PILOT_SERIALS', ''))))),
    // Empty list means arbitrary source networks are allowed (not authenticated).
    'ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMS_PILOT_ALLOWED_IPS', ''))))),
];
