<?php

return [
    'reasons' => [
        'DNS_ERROR' => 'DNS could not be resolved', 'CONNECTION_TIMEOUT' => 'Connection timed out',
        'CONNECTION_ERROR' => 'Connection failed', 'TLS_ERROR' => 'TLS certificate or handshake failed',
        'HTTP_5XX' => 'The website returned a server error', 'SITE_UNREACHABLE' => 'The website is unreachable',
        'WP_BRIDGE_UNREACHABLE' => 'WordPress Bridge is not responding', 'WP_BRIDGE_AUTH_ERROR' => 'WordPress Bridge authentication failed',
        'HEARTBEAT_INVALID' => 'WordPress Bridge returned an invalid heartbeat',
    ],
    'banner' => [
        'critical' => '{1} :count website has a critical issue|[2,*] :count websites have critical issues',
        'warning' => '{1} :count website needs attention|[2,*] :count websites need attention',
        'more' => 'and :count more', 'detected' => 'Detected :time',
    ],
    'actions' => ['view_details' => 'View details', 'view_all' => 'View all', 'dismiss' => 'Dismiss', 'retry' => 'Check again now', 'open_site' => 'Open website'],
    'drawer' => ['title' => 'Site Health diagnostics'],
    'fields' => ['domain' => 'Domain', 'status' => 'Status', 'error_code' => 'Error code', 'reason' => 'Reason', 'detected_at' => 'Detected at', 'duration' => 'Duration', 'last_checked_at' => 'Last checked', 'last_success_at' => 'Last successful check', 'failures' => 'Consecutive failures', 'diagnostics' => 'Diagnostic stages', 'technical_error' => 'Latest technical error'],
    'notification' => ['active_title' => 'Site Health issue: :site', 'recovered_title' => 'Website recovered: :site', 'recovered_message' => 'The website is reachable again.'],
];
