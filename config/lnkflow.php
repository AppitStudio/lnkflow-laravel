<?php

declare(strict_types=1);

return [
    'default' => env('LNKFLOW_CONNECTION', 'default'),

    'connections' => [
        'default' => [
            'url' => env('LNKFLOW_API_URL', 'https://app.lnkflow.io/api/v1'),

            // A general-purpose token, used whenever a purpose-specific one
            // below is not set. Prefer the narrower tokens for automation.
            'api_token' => env('LNKFLOW_API_TOKEN'),

            // Link and resource management. Needs the `write` ability.
            'link_token' => env('LNKFLOW_LINK_TOKEN'),

            // Journey and conversion reporting. Needs the `conversions`
            // ability; it does not need `write`.
            'conversion_token' => env('LNKFLOW_CONVERSION_TOKEN'),

            // The numeric LnkFlow team id, not a user id and not a slug.
            'team' => env('LNKFLOW_TEAM'),

            // The numeric website id this application reports against.
            'website' => env('LNKFLOW_WEBSITE'),

            'connect_timeout' => 3,
            'timeout' => 10,
            'attempts' => 3,
            'retry_base_milliseconds' => 150,

            // The longest this process will ever block between attempts. When
            // the server asks for longer than this via `Retry-After`, the SDK
            // stops retrying and throws RateLimitException instead, so a queued
            // caller can release the job for the real delay rather than pinning
            // a PHP-FPM worker on `sleep`.
            'retry_max_wait_milliseconds' => 2000,

            // Stay inside the server's request budgets rather than firing into
            // 429s. Fails open: if a budget is exhausted for longer than
            // `max_wait_milliseconds`, the request still goes out and the
            // server's own 429 plus Retry-After becomes the authority.
            //
            // These mirror the API's named rate limiters, which are per
            // endpoint group — there is no single global budget. Link creation
            // is by far the tightest, which is why an unthrottled CMS backfill
            // is the thing that actually hits 429s.
            'throttle' => [
                'enabled' => env('LNKFLOW_THROTTLE', true),
                'max_wait_milliseconds' => 2000,
                'store' => null,
                // null means "no client-side limit", which is the honest
                // default for endpoints the server does not throttle. Do not
                // invent a budget here: an imposed limit the server does not
                // have shows up as unexplained slowness, not as protection.
                'budgets' => [
                    // Reads carry no server-side limiter today.
                    'default' => null,
                    // `throttle:link-creation`, 20/min per user. This is the
                    // one that a CMS backfill actually hits.
                    'link_creation' => 20,
                    // `throttle:conversion-tracking`, 600/min per team.
                    'conversions' => 600,
                    // `throttle:journey-capture`, 600/min per team.
                    'journeys' => 600,
                ],
            ],
        ],
    ],

    // Which automatic integrations are wired up. Everything here is off by
    // default; explicit client calls never need a feature flag.
    'features' => [
        // Observe configured Eloquent models and keep their links in sync.
        'content' => false,

        // Capture journey touchpoints from inbound tracked clicks.
        'journeys' => false,

        // Identify and unidentify visitors on login and logout.
        'auth_identity' => false,

        // Enable the configured conversion mappers and the Cashier adapter.
        'conversions' => false,
    ],

    'content' => [
        'models' => [],
        'preview_before_write' => true,
        'queue' => null,
    ],

    'journeys' => [
        'clean_url' => false,
        'session_key' => '_lnkflow',
        'app_namespace' => env('APP_NAME', 'app'),
        'queue' => null,

        // Defaults for the <x-lnkflow-script /> component, which renders the
        // hosted browser snippet. The component renders whenever it is used;
        // the site key is what enables browser-side journey capture, not what
        // gates the tag. Never render a visitor or click id into HTML.
        'script' => [
            'url' => env('LNKFLOW_SCRIPT_URL', 'https://app.lnkflow.io/lnk.js'),
            'site_key' => env('LNKFLOW_SITE_KEY'),
            // cookie | manual | none. `manual` stores nothing until the
            // host's CMP calls window.lnkflow.setConsent({storage: true}).
            'storage' => 'cookie',

            // auto | manual | none — how checkout links get decorated.
            'attribution' => 'auto',

            // Opt in to auto-tagging buy.stripe.com anchors on the page.
            'stripe' => false,

            // Required alongside the site key for browser-side journey capture.
            'capture_endpoint' => env('LNKFLOW_CAPTURE_ENDPOINT'),

            'cookie_days' => 90,
        ],
    ],

    'conversions' => [
        'mappers' => [],
        'queue' => null,
    ],

    'cashier' => [
        'enabled' => false,
        'include_test_events' => false,
    ],

    // Safe request diagnostics: endpoint, status, attempt, duration, team, and
    // request id. Never the token, the payload, or customer details.
    'logging' => [
        'enabled' => env('LNKFLOW_LOGGING', false),
        'channel' => env('LNKFLOW_LOG_CHANNEL'),
    ],
];
