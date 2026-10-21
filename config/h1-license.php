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
    | pre_enrollment — existing installs keep working until enrolled + cutover.
    | enforcing — licensed SEO/Seeding require a verified entitlement snapshot.
    */
    'enforcement_mode' => (string) env('H1_ENFORCEMENT_MODE', 'pre_enrollment'),

    'schedule_enabled' => (bool) env('H1_RECONCILE_SCHEDULE_ENABLED', false),

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
];
