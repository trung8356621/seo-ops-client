<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Final cutover: remove generic Business Hook automation from addon DB.
 * Authoritative SoT is Client Core (mysql / omi_client).
 * Does NOT touch seo_agent_automations*.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    /** @var list<string> */
    private const DROP_ORDER = [
        'automation_node_executions',
        'automation_action_executions',
        'automation_executions',
        'automation_rule_version_edges',
        'automation_rule_version_nodes',
        'automation_rule_versions',
        'automation_rule_edges',
        'automation_rule_nodes',
        'automation_rule_actions',
        'automation_rules',
        'automation_action_runs',
        'automation_scheduler_heartbeats',
        'business_events',
    ];

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        foreach (self::DROP_ORDER as $table) {
            $schema->dropIfExists($table);
        }
    }

    public function down(): void
    {
        // Irreversible — restore from backup. Core schema lives on mysql.
    }
};
