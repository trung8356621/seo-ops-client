<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Automation runtime connection
    |--------------------------------------------------------------------------
    |
    | Canonical: Client Core DB via default mysql connection (omi_client).
    | Missing AUTOMATION_DB_CONNECTION must NEVER fall back to omi_seo_ai.
    |
    */

    'connection' => env('AUTOMATION_DB_CONNECTION', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Legacy data migration (automation:migrate-to-core) — upgrade only
    |--------------------------------------------------------------------------
    */

    'source_connection' => env('AUTOMATION_SOURCE_DB_CONNECTION', 'omi_seo_ai'),

    'target_connection' => env(
        'AUTOMATION_TARGET_DB_CONNECTION',
        env('DB_CORE_CONNECTION', env('DB_CONNECTION', 'mysql'))
    ),

    'chunk_size' => (int) env('AUTOMATION_MIGRATE_CHUNK_SIZE', 500),

    'report_directory' => 'automation-migration',

];
