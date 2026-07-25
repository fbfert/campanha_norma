<?php

return [
    'provider' => env('WHATSAPP_PROVIDER', 'web'),

    'service' => [
        'url' => env('WHATSAPP_SERVICE_URL', 'http://127.0.0.1:3100'),
        'token' => env('WHATSAPP_SERVICE_TOKEN'),
        'timeout' => (int) env('WHATSAPP_SERVICE_TIMEOUT', 15),
        'connect_timeout' => (int) env('WHATSAPP_SERVICE_CONNECT_TIMEOUT', 5),
    ],

    'status_cache_seconds' => (int) env('WHATSAPP_STATUS_CACHE_SECONDS', 5),
    'test_message_enabled' => (bool) env('WHATSAPP_TEST_MESSAGE_ENABLED', false),
    'incoming' => [
        'enabled' => (bool) env('WHATSAPP_INCOMING_ENABLED', true),
        'secret' => env('WHATSAPP_INCOMING_SECRET'),
        'timestamp_tolerance' => (int) env('WHATSAPP_INCOMING_TIMESTAMP_TOLERANCE', 300),
        'max_body_size' => (int) env('WHATSAPP_INCOMING_MAX_BODY_SIZE', 262144),
        'queue' => env('WHATSAPP_INCOMING_QUEUE', 'whatsapp-incoming'),
    ],
];
