<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop confirmed dead / empty shell tables from addon DB.
 * Backups exist; SoT moved to wp_options / site_meta / keyword_tags / prompts.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    /** @var list<string> */
    private const DEAD_TABLES = [
        'entity_results',
        'entities',
        'tags',
        'seo_settings',
        'seo_domain_metas',
        'domain_global_cta_settings',
        'user_workspace_settings',
        'seo_prompt_templates',
    ];

    public function up(): void
    {
        $connection = (string) $this->connection;
        $schema = Schema::connection($connection);

        // Drop child FK tables first; disable checks for leftover constraints.
        DB::connection($connection)->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (self::DEAD_TABLES as $table) {
                $schema->dropIfExists($table);
            }
        } finally {
            DB::connection($connection)->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    public function down(): void
    {
        // Irreversible — restore from backup.
    }
};
