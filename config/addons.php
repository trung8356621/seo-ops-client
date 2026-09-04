<?php

declare(strict_types=1);

// Thin shell override — discovery roots only. Package defaults merge first via ClientCoreServiceProvider.
return [

    'skip_slugs' => array_values(array_filter(array_map(
        static fn (string $slug): string => trim($slug),
        explode(',', (string) env('ADDON_SKIP_SLUGS', 'wp-headless')),
    ))),

    /*
    | Absolute or base_path-relative path to the addons monorepo root.
    | Default: client/addons junction → ../omnichannel-addons
    */
    'addons_path' => env('OMNICHANNEL_ADDONS_PATH', 'addons'),

    /*
    | Discovery roots (order matters — later overrides earlier on same slug).
    | Do NOT hard-code business provider class names in the Laravel shell.
    */
    'discovery_roots' => array_values(array_filter([
        env('OMNICHANNEL_ADDONS_PATH', 'addons'),
    ])),

    'entitlement' => [
        'enabled' => (bool) env('ADDON_ENTITLEMENT_CHECKS', false),
        'resolver' => null,
    ],

    'peer_slugs' => [
        'search-foundation',
        'seo',
        'search-intelligence',
        'ai-prompt',
        'content',
        'content-projects',
        'media',
        'wordpress',
        'publishing',
        'site-sync',
        'agent',
        'social',
        'commerce',
        'seeding',
        'seo-content-ai',
    ],

];
