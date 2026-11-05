<?php

return [
    'enabled' => filter_var(env('ADMS_PILOT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'serials' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMS_PILOT_SERIALS', ''))))),
    'ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMS_PILOT_ALLOWED_IPS', ''))))),
];
