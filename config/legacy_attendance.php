<?php

return [
    // Disabled unless explicitly enabled on a separate test deployment.
    // The SQL Server login must be SELECT-only.
    'browser_enabled' => filter_var(env('LEGACY_SQLSRV_BROWSER_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
];
