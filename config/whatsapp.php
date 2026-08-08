<?php

return [
    'provider' => env('WHATSAPP_PROVIDER', 'web'),

    'service' => [
        'url' => env('WHATSAPP_SERVICE_URL', 'http://127.0.0.1:3100'),
        'token' => env('WHATSAPP_SERVICE_TOKEN'),
        'timeout' => (int) env('WHATSAPP_SERVICE_TIMEOUT', 15),
        'connect_timeout' => (int) env('WHATSAPP_SERVICE_CONNECT_TIMEOUT', 5),
    ],

    /*
     | API oficial da Meta. O número e o token vêm do Business Manager; o
     | `verify_token` é escolhido por nós e repetido na configuração do webhook,
     | e o `app_secret` é o que assina cada requisição que a Meta manda.
     */
    'meta' => [
        'base_url' => env('META_BASE_URL', 'https://graph.facebook.com'),
        'api_version' => env('META_API_VERSION', 'v21.0'),
        'phone_number_id' => env('META_PHONE_NUMBER_ID'),
        'business_account_id' => env('META_BUSINESS_ACCOUNT_ID'),
        'token' => env('META_TOKEN'),
        'app_secret' => env('META_APP_SECRET'),
        'verify_token' => env('META_VERIFY_TOKEN'),
        'timeout' => (int) env('META_TIMEOUT', 20),
        'connect_timeout' => (int) env('META_CONNECT_TIMEOUT', 5),
        // Template aprovado que abre a conversa. Fora da janela de 24 horas,
        // nenhuma outra mensagem sai.
        'invite_template' => env('META_INVITE_TEMPLATE'),
        'invite_language' => env('META_INVITE_LANGUAGE', 'pt_BR'),
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
