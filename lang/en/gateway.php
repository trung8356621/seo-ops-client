<?php

declare(strict_types=1);

return [
    'nav' => 'Gateway',
    'title' => 'Gateway',
    'tab_api_access' => 'API Access Gate',
    'tab_control' => 'License & Services',

    'control_title' => 'Control Gate — License & Services',
    'control_description' => 'OPS-SERVER entitlement presentation and Service readiness. Machine credentials and internal Service Gate secrets are never shown.',
    'control_product' => 'Product',
    'control_plan' => 'Plan',
    'control_license_status' => 'License status',
    'control_starts_at' => 'License start',
    'control_ends_at' => 'License end',
    'control_h1_label' => 'H1 receiver',
    'control_h1_pending_integration' => 'Pending integration (not implemented in this task)',
    'control_h1_not_implemented' => 'Not implemented',
    'control_no_info' => 'No information',
    'control_services_title' => 'Entitled services',
    'control_service' => 'Service',
    'control_entitled' => 'Entitled',
    'control_activated' => 'Activated',
    'control_readiness' => 'Readiness',
    'control_no_services' => 'No catalog services found.',
    'control_no_secrets' => 'Machine secrets, internal service keys, rotation versions, and technical command IDs are never shown.',

    'license_status_disconnected' => 'Not connected',
    'license_status_active' => 'Active',
    'license_status_expired' => 'Expired',
    'license_status_revoked' => 'Revoked',
    'license_status_unknown' => 'Unknown',

    'readiness_disconnected' => 'Not connected',
    'readiness_not_entitled' => 'Not entitled',
    'readiness_pending' => 'Pending',
    'readiness_ready' => 'Ready',
    'readiness_not_required' => 'Ready (no managed credential)',
    'readiness_revoked' => 'Revoked',
    'readiness_error' => 'Error — retry available',
    'readiness_unknown' => 'Unknown',

    'api_access_title' => 'API Access Gate',
    'api_access_description' => 'Root-managed local API keys for external integrations. Separate from Control Gate and Service Gate managed credentials. Raw keys are shown once only.',
    'api_access_create' => 'Create API key',
    'api_access_service' => 'Service',
    'api_access_scopes_helper' => 'Allowlisted scopes only (no wildcard). service:read unlocks /api/v1/me/capabilities and service status.',
    'api_access_empty' => 'No user API credentials yet.',
    'api_access_root_only' => 'Only the account owner (Root) can create, revoke, or rotate API keys.',

    'summary_active' => 'Active keys',
    'summary_total' => 'Total keys',
    'yes' => 'Yes',
    'no' => 'No',
];
