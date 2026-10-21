<?php

declare(strict_types=1);

return [
    'nav' => 'Gateway',
    'title' => 'Gateway',
    'tab_api_access' => 'API Access Gate',
    'tab_control' => 'Control Gate',

    'control_title' => 'Control Gate',
    'control_description' => 'OPS-SERVER license / installation control plane. Status display only — credentials and H1 commands are not managed here.',
    'control_h1_label' => 'H1 local receiver',
    'control_h1_not_implemented' => 'Not implemented in this client build',
    'control_entitlement_label' => 'Local entitlement enforcement',
    'control_entitlement_on' => 'Enabled',
    'control_entitlement_off' => 'Disabled (pass-through)',
    'control_service' => 'Service',
    'control_active' => 'Active',
    'control_key_provisioned' => 'Provisioned key present',
    'control_no_services' => 'No catalog services found.',
    'control_no_secrets' => 'Machine secrets and service_key values are never shown.',

    'api_access_title' => 'API Access Gate',
    'api_access_description' => 'Root-managed local API keys for external integrations. Separate from OPS-SERVER machine credentials. Raw keys are shown once only.',
    'api_access_create' => 'Create API key',
    'api_access_service' => 'Service',
    'api_access_scopes_helper' => 'Allowlisted scopes only (no wildcard). service:read unlocks /api/v1/me/capabilities and service status.',
    'api_access_empty' => 'No API credentials yet.',
    'api_access_root_only' => 'Only the account owner (Root) can create, revoke, or rotate API keys.',

    'summary_active' => 'Active keys',
    'summary_total' => 'Total keys',
    'yes' => 'Yes',
    'no' => 'No',
];
