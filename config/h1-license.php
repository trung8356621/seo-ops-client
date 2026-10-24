<?php

declare(strict_types=1);

/**
 * seo-ops-license/1 client receiver configuration.
 *
 * Schedules stay disabled until G3. Enforcement stays in pre_enrollment until
 * a verified enrollment exists and an approved cutover is set.
 */
return [
    'protocol' => 'seo-ops-license/1',
    'product' => 'seo-ops',

    'base_url' => rtrim((string) env('OPS_SERVER_BASE_URL', env('CONFIGURATION_SYNC_BASE_URL', '')), '/'),
    'require_https' => (bool) env('H1_REQUIRE_HTTPS', true),
    'timeout_seconds' => (int) env('H1_HTTP_TIMEOUT', 15),
    'max_body_bytes' => (int) env('H1_MAX_BODY_BYTES', 262144),
    'timestamp_skew_seconds' => (int) env('H1_TIMESTAMP_SKEW_SECONDS', 300),

    /*
    | pre_enrollment — legacy unenrolled installs fail-open until they enroll.
    |                   Once enrolled, ServiceEntitlementGate always enforces the
    |                   verified H1 snapshot (enrollment is the cutover).
    | enforcing — force snapshot checks even before enrollment (deny commercial).
    */
    'enforcement_mode' => (string) env('H1_ENFORCEMENT_MODE', 'pre_enrollment'),

    /*
    | Periodic pull of pending entitlement/rotation commands from OPS-SERVER.
    | Services Admin also reconciles on page load. Enable for enrolled installs.
    */
    'schedule_enabled' => (bool) env('H1_RECONCILE_SCHEDULE_ENABLED', true),

    'queue' => (string) env('H1_RECONCILE_QUEUE', 'default'),

    /*
    | Enrollment HTTP path from handoff — NOT present on OPS-SERVER HTTP app yet.
    | Transport defaults to blocked until Server exposes the route.
    */
    'enrollment' => [
        'path' => '/api/v1/enrollment/consume',
        'transport' => (string) env('H1_ENROLLMENT_TRANSPORT', 'blocked'),
    ],

    /*
    | Rotation secret delivery endpoint — domain exists on Server; no HTTP route yet.
    */
    'rotation' => [
        'path_template' => '/api/v1/installations/{installation_id}/license/rotations/{rotation_id}/secret',
        'transport' => (string) env('H1_ROTATION_TRANSPORT', 'blocked'),
    ],

    'reconcile' => [
        'path_template' => '/api/v1/installations/{installation_id}/license/reconcile',
    ],

    /*
    | Widget token exchange — machine-signed mint of short-lived Service Widget bearer tokens.
    | Response body never contains H1 credential secrets.
    */
    'widget' => [
        'path_template' => '/api/v1/installations/{installation_id}/widget/token',
        'timeout_seconds' => (int) env('OPS_SERVICE_WIDGET_TIMEOUT', 5),
        /*
        | Static React IIFE bundle URL (prefer SEO-OPS local copy).
        | Override with absolute OPS-SERVER/CDN URL when needed.
        */
        'bundle_url' => (string) env(
            'OPS_SERVICE_WIDGET_BUNDLE_URL',
            '/vendor/ops-service-widget/ops-service-widget.js'
        ),
        'api_timeout_ms' => (int) env('OPS_SERVICE_WIDGET_API_TIMEOUT_MS', 5000),
    ],

    /*
    | Support Widget — same H1 token exchange; separate React IIFE bundle.
    */
    'support' => [
        'bundle_url' => (string) env(
            'OPS_SUPPORT_WIDGET_BUNDLE_URL',
            '/vendor/ops-support-widget/ops-support-widget.js'
        ),
        'api_timeout_ms' => (int) env('OPS_SUPPORT_WIDGET_API_TIMEOUT_MS', 8000),
    ],
];

