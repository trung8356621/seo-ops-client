<?php

declare(strict_types=1);

/**
 * Canonical SEO-OPS aaPanel cron catalog (short-lived flock workers).
 * Paths are defaults only — override via UI / env; never hardcode in Blade.
 */
return [
    'defaults' => [
        'project_directory' => (string) env('AAPANEL_CRON_PROJECT_DIR', '/www/wwwroot/seo-ops-client'),
        'php_binary' => (string) env('AAPANEL_CRON_PHP_BINARY', '/www/server/php/83/bin/php'),
    ],

    /**
     * Fixed aaPanel JSON fields (unused for shell workers) — match panel sample, do not invent schema.
     *
     * @var array<string, mixed>
     */
    'aapanel_defaults' => [
        'type' => 'minute-n',
        'where1' => '1',
        'where_hour' => '0',
        'where_minute' => '0',
        'week' => '1',
        'hour' => '1',
        'minute' => '30',
        'second' => '',
        'user' => 'root',
        'status' => 1,
        'save' => '',
        'backupTo' => '',
        'sName' => 'ALL',
        'sType' => 'toShell',
        'urladdress' => 'http://',
        'save_local' => 0,
        'notice' => 0,
        'notice_channel' => '',
        'db_type' => 'mysql',
        'split_type' => '',
        'split_value' => 3,
        'flock' => 1,
        'timeSet' => 1,
        'db_backup_path' => '/www/backup',
        'timeType' => 'sday',
        'special_time' => '',
        'log_cut_path' => '/www/wwwlogs/history_backups',
        'user_agent' => '',
        'version' => '',
        'table_list' => '',
        'zip_password' => '',
    ],

    /**
     * Exactly five SEO-OPS production cron tasks. Do not add SSL/backup/system jobs.
     *
     * @var list<array<string, mixed>>
     */
    'tasks' => [
        [
            'id' => 'scheduler',
            'name' => 'SEO-OPS Scheduler',
            'purpose_key' => 'scheduler',
            'queue_label' => 'Laravel Scheduler',
            'lock_file' => '/tmp/seo-ops-scheduler.lock',
            'log_file' => 'storage/logs/cron-schedule.log',
            'kind' => 'schedule',
        ],
        [
            'id' => 'external',
            'name' => 'SEO-OPS External',
            'purpose_key' => 'external',
            'queue_label' => 'automation-external',
            'lock_file' => '/tmp/seo-ops-external.lock',
            'log_file' => 'storage/logs/queue-external-cron.log',
            'kind' => 'queue',
            'queues' => 'automation-external',
            'max_jobs' => 10,
            'timeout' => 900,
            'tries' => 3,
            'sleep' => 1,
            'max_time' => 55,
        ],
        [
            'id' => 'content',
            'name' => 'SEO-OPS Content',
            'purpose_key' => 'content',
            'queue_label' => 'seo-content-run',
            'lock_file' => '/tmp/seo-ops-content-run.lock',
            'log_file' => 'storage/logs/content-run-queue-cron.log',
            'kind' => 'queue',
            'queues' => 'seo-content-run',
            'max_jobs' => 1,
            'timeout' => 900,
            'tries' => 1,
            'sleep' => 1,
            // no max_time — long Content Project generation must not be capped at 55s
        ],
        [
            'id' => 'critical',
            'name' => 'SEO-OPS Critical',
            'purpose_key' => 'critical',
            'queue_label' => 'automation-critical',
            'lock_file' => '/tmp/seo-ops-critical.lock',
            'log_file' => 'storage/logs/queue-critical-cron.log',
            'kind' => 'queue',
            'queues' => 'automation-critical',
            'max_jobs' => 20,
            'timeout' => 300,
            'tries' => 3,
            'sleep' => 1,
            'max_time' => 55,
        ],
        [
            'id' => 'general',
            'name' => 'SEO-OPS General',
            'purpose_key' => 'general',
            'queue_label' => 'automation,seo,media_generation,default',
            'lock_file' => '/tmp/seo-ops-general.lock',
            'log_file' => 'storage/logs/queue-general-cron.log',
            'kind' => 'queue',
            'queues' => 'automation,seo,media_generation,default',
            'max_jobs' => 10,
            'timeout' => 300,
            'tries' => 3,
            'sleep' => 1,
            'max_time' => 55,
        ],
    ],
];
