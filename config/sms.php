<?php

return [
    'driver' => env('SMS_DRIVER', 'python'),

    'python_api_url' => env('SMS_PYTHON_API_URL'),

    // Send path appended to python_api_url. Default: /send (production).
    // Override temporarily with SMS_PYTHON_API_SEND_PATH for smoke-test stubs.
    // Revert by removing the env line or unsetting it.
    'python_api_send_path' => env('SMS_PYTHON_API_SEND_PATH', '/send'),
];
