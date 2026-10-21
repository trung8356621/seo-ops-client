<?php

declare(strict_types=1);

return [
    'protocol' => 'seo-ops-config/1',
    'service_key' => 'seo',
    'base_url' => env('SEO_OPS_CONFIG_BASE_URL', ''),
    'require_https' => filter_var(env('SEO_OPS_CONFIG_REQUIRE_HTTPS', true), FILTER_VALIDATE_BOOL),
    'timeout_seconds' => (int) env('SEO_OPS_CONFIG_TIMEOUT', 20),
    /*
     * Stays false until a real OPS-SERVER database and H1 credential succeed.
     * Manual `seo:config-compare` and `seo:config-sync` remain available.
     */
    'schedule_enabled' => filter_var(env('SEO_OPS_CONFIG_SCHEDULE', false), FILTER_VALIDATE_BOOL),
    'client_version' => env('SEO_OPS_CLIENT_VERSION', '12.50.0'),
    'addons_version' => env('SEO_OPS_ADDONS_VERSION', '0.1.0'),
    'bootstrap_user_id' => (int) env('SEO_OPS_CONFIG_BOOTSTRAP_USER_ID', 1),
    'package_id' => 'seo-ops-golden-configuration',
    'state_path' => storage_path('app/configuration-sync/seo.json'),
];
