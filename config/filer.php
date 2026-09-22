<?php

return [
    'reserve_bytes' => (int) env('FILER_RESERVE_BYTES', 5 * 1024 * 1024 * 1024),
    'office_binary' => env('FILER_OFFICE_BINARY', PHP_OS_FAMILY === 'Windows' ? 'C:/Program Files/LibreOffice/program/soffice.exe' : '/usr/bin/soffice'),
    'office_sandbox' => env('FILER_OFFICE_SANDBOX', '/usr/bin/bwrap'),
    'preview_connection' => env('FILER_PREVIEW_CONNECTION', 'database'),
    'preview_max_bytes' => 50 * 1024 * 1024,
];
