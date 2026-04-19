<?php

return [
    'driver' => env('SMS_DRIVER', 'python'),

    'python_api_url' => env('SMS_PYTHON_API_URL'),

    // Send path appended to python_api_url. Default: /send (production).
    // Override temporarily with SMS_PYTHON_API_SEND_PATH for smoke-test stubs.
    // Revert by removing the env line or unsetting it.
    'python_api_send_path' => env('SMS_PYTHON_API_SEND_PATH', '/send'),

    // Runtime integration endpoints used for watchdog health and runtime visibility.
    'python_api_health_path' => env('SMS_PYTHON_API_HEALTH_PATH', '/modems/health'),
    'python_api_discover_path' => env('SMS_PYTHON_API_DISCOVER_PATH', '/modems/discover'),

    // Shared timeout for runtime calls (health/discovery/send).
    'python_api_timeout_seconds' => (int) env('SMS_PYTHON_API_TIMEOUT_SECONDS', 35),

    // Shared secret sent as X-Gateway-Token header on every Python API call.
    // When empty (default), no auth header is sent — preserves backward compatibility
    // during initial deployment. Set on both Laravel and Python sides together.
    'python_api_token' => env('SMS_PYTHON_API_TOKEN', ''),
];
