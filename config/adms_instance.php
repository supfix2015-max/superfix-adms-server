<?php

// Use dual for one Coolify app with AT1 + AT2 hosts; transition remains backward compatible.
// AT1 is the only device HTTP gateway; AT2 is the HTTPS console/ZenHR exporter.
return [
    'role' => env('ADMS_INSTANCE_ROLE', 'transition'),
    'device_host' => 'at1.superfix.ae',
    'console_host' => 'at2.superfix.ae',
];
