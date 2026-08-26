<?php

$appOrigin = rtrim((string) env('APP_URL', 'http://localhost'), '/');
$extraOrigins = array_filter(array_map('trim', explode(',', (string) env('SPA_ALLOWED_ORIGINS', ''))));

return [
    'request_header' => env('SPA_REQUEST_HEADER', 'Zuratax'),
    'allowed_origins' => array_values(array_unique([$appOrigin, ...array_map(
        static fn (string $origin): string => rtrim($origin, '/'),
        $extraOrigins,
    )])),
];
