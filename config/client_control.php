<?php

declare(strict_types=1);

return [
    /*
    | Passive controlled client. The client never polls ops-server.
    | The only client-initiated control request is explicit enrollment (Connect).
    */
    'client_version' => env('CLIENT_VERSION', '0.0.9'),

    'enroll_path' => '/api/control/v1/enrollments',

    'http_timeout_seconds' => 10,

    'http_connect_timeout_seconds' => 5,

    /*
    | Reject signed commands whose issued_at is older than this window.
    | Also rejects issued_at too far in the future (clock skew).
    */
    'command_max_age_seconds' => (int) env('CLIENT_CONTROL_COMMAND_MAX_AGE', 300),

    'command_future_skew_seconds' => (int) env('CLIENT_CONTROL_FUTURE_SKEW', 30),

    'signature_header' => 'X-Omi-Control-Signature',
];
