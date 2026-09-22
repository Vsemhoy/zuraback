<?php

return [
    'url' => env('HOST_METRICS_URL', 'http://127.0.0.1:9187'),
    'token' => env('HOST_METRICS_TOKEN'),
    'token_file' => env('HOST_METRICS_TOKEN_FILE', '/var/www/zuratax/shared/host-metrics.token'),
];
