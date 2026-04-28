<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'chat_app' => [
        'inbound_url' => env('CHAT_APP_INBOUND_URL'),
        'delivery_status_url' => env('CHAT_APP_DELIVERY_STATUS_URL'),
        'timeout' => env('CHAT_APP_TIMEOUT', 10),
        'tenant_key' => env('CHAT_APP_TENANT_KEY'),
        'inbound_secret' => env('CHAT_APP_INBOUND_SECRET'),
        'platform_key' => env('CHAT_APP_PLATFORM_KEY'),
        'platform_secret' => env('CHAT_APP_PLATFORM_SECRET'),
        'platform_timestamp_tolerance_seconds' => env('CHAT_APP_PLATFORM_TIMESTAMP_TOLERANCE_SECONDS', 300),
    ],

    'gateway' => [
        'outbound_retry_base_delay_seconds' => env('GATEWAY_OUTBOUND_RETRY_BASE_DELAY_SECONDS', 10),
        'outbound_retry_all_failures' => env('GATEWAY_OUTBOUND_RETRY_ALL_FAILURES', true),
        'outbound_stale_lock_seconds' => env('GATEWAY_OUTBOUND_STALE_LOCK_SECONDS', 300),

        'inbound_relay_retry_max_attempts' => env('GATEWAY_INBOUND_RELAY_RETRY_MAX_ATTEMPTS', 3),
        'inbound_relay_retry_base_delay_seconds' => env('GATEWAY_INBOUND_RELAY_RETRY_BASE_DELAY_SECONDS', 30),
        'inbound_relay_retry_max_delay_seconds' => env('GATEWAY_INBOUND_RELAY_RETRY_MAX_DELAY_SECONDS', 300),
        'inbound_relay_lock_seconds' => env('GATEWAY_INBOUND_RELAY_LOCK_SECONDS', 120),

        // Soft non-sticky assignment pressure controls (no hard disable):
        // SIMs breaching queue/failure thresholds are temporarily held for new assignments.
        'sim_selection_hysteresis_hold_seconds' => env('GATEWAY_SIM_SELECTION_HYSTERESIS_HOLD_SECONDS', 300),
        'sim_selection_failure_window_minutes' => env('GATEWAY_SIM_SELECTION_FAILURE_WINDOW_MINUTES', 15),
        'sim_selection_failure_hold_threshold' => env('GATEWAY_SIM_SELECTION_FAILURE_HOLD_THRESHOLD', 3),
        'sim_selection_queue_hold_threshold' => env('GATEWAY_SIM_SELECTION_QUEUE_HOLD_THRESHOLD', 100),
        'runtime_failure_window_minutes' => env('GATEWAY_RUNTIME_FAILURE_WINDOW_MINUTES', 15),
        'runtime_failure_threshold' => env('GATEWAY_RUNTIME_FAILURE_THRESHOLD', 3),
        'runtime_suppression_minutes' => env('GATEWAY_RUNTIME_SUPPRESSION_MINUTES', 15),
    ],

];
