<?php

declare(strict_types=1);

return [
    'default' => env('LNKFLOW_CONNECTION', 'default'),

    'connections' => [
        'default' => [
            'url' => env('LNKFLOW_API_URL', 'https://app.lnkflow.io/api/v1'),
            'api_token' => env('LNKFLOW_API_TOKEN'),
            'link_token' => env('LNKFLOW_LINK_TOKEN'),
            'conversion_token' => env('LNKFLOW_CONVERSION_TOKEN'),
            'team' => env('LNKFLOW_TEAM'),
            'website' => env('LNKFLOW_WEBSITE'),
            'connect_timeout' => 3,
            'timeout' => 10,
            'attempts' => 3,
            'retry_base_milliseconds' => 150,
        ],
    ],

    'features' => [
        'links' => false,
        'content' => false,
        'journeys' => false,
        'auth_identity' => false,
        'conversions' => false,
    ],

    'content' => [
        'models' => [],
        'preview_before_write' => true,
        'queue' => null,
    ],

    'journeys' => [
        'middleware' => false,
        'clean_url' => false,
        'session_key' => '_lnkflow',
        'app_namespace' => env('APP_NAME', 'app'),
        'queue' => null,
    ],

    'conversions' => [
        'mappers' => [],
        'queue' => null,
    ],

    'cashier' => [
        'enabled' => false,
        'include_test_events' => false,
    ],

    'logging' => [
        'enabled' => false,
        'channel' => null,
    ],
];
