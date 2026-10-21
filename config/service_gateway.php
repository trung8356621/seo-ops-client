<?php

declare(strict_types=1);

/**
 * Local Service Gate capability manifest.
 * Declares whether a Service needs a managed internal API credential.
 * Does not open inbound HTTP endpoints by itself.
 */
return [
    'services' => [
        'seo' => [
            'requires_managed_api_credential' => true,
            'managed_scopes' => ['service:read'],
            'opens_inbound_http' => false,
        ],
        'seeding' => [
            'requires_managed_api_credential' => false,
            'managed_scopes' => [],
            'opens_inbound_http' => false,
        ],
    ],
];
