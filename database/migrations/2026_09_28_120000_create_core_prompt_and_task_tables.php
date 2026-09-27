<?php

declare(strict_types=1);

use App\Services\PromptTask\PromptTaskCoreMigrationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create canonical Prompt + Task tables on the Client Core database (mysql / omi_client).
 *
 * Transfers existing data from omi_seo_ai if present, preserving all IDs, versions, and relationships.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = (string) config('database.core_connection', config('database.default', 'mysql'));
        $schema = Schema::connection($connection);

        // 1. seo_tasks (Parent)
        if (! $schema->hasTable('seo_tasks')) {
            $schema->create('seo_tasks', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('name', 255);
                $table->text('description')->nullable();
                $table->json('flow_data')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // 2. task_test_results (Child of seo_tasks)
        if (! $schema->hasTable('task_test_results')) {
            $schema->create('task_test_results', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('task_id');
                $table->unsignedBigInteger('user_id')->index();
                $table->string('status', 32)->default('completed');
                $table->json('input_snapshot')->nullable();
                $table->json('resolved_context')->nullable();
                $table->json('step_results')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->foreign('task_id')->references('id')->on('seo_tasks')->cascadeOnDelete();
            });
        }

        // 3. prompts (Parent)
        if (! $schema->hasTable('prompts')) {
            $schema->create('prompts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('current_prompt_version_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('ai_connection_id')->nullable()->index();
                $table->string('routing_mode', 16)->default('auto');
                $table->string('routing_profile_key', 64)->nullable();
                $table->string('routing_policy', 32)->nullable();
                $table->string('model_category', 50)->nullable()->comment('Nhãn đại diện: gemini_pro, gemini_flash, imagen_pro...');
                $table->string('name', 255)->nullable();
                $table->string('tools', 32)->nullable();
                $table->string('hook_key', 128)->nullable()->index();
                $table->string('hook_version', 32)->nullable();
                $table->json('hook_settings')->nullable();
                $table->longText('markdown_content')->nullable();
                $table->json('variables')->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('title', 255);
                $table->text('description')->nullable();
                $table->string('status', 32)->default('draft');
                $table->string('ai_model', 64)->nullable();
                $table->json('settings')->nullable();
                $table->json('prompt_data')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->char('portable_uuid', 36)->nullable()->unique();
            });
        }

        // 4. prompt_versions (Child of prompts)
        if (! $schema->hasTable('prompt_versions')) {
            $schema->create('prompt_versions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('prompt_id')->index();
                $table->string('version_label', 32);
                $table->unsignedInteger('sequence')->default(1);
                $table->longText('markdown_content')->nullable();
                $table->string('hook_key', 255)->nullable();
                $table->string('hook_version', 255)->nullable();
                $table->json('hook_settings')->nullable();
                $table->json('settings')->nullable();
                $table->string('tools', 64)->nullable();
                $table->json('variables')->nullable();
                $table->char('content_fingerprint', 64)->index();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->timestamp('created_at')->nullable();

                $table->unique(['prompt_id', 'sequence'], 'prompt_versions_prompt_sequence_unique');
                $table->index(['prompt_id', 'version_label'], 'prompt_versions_prompt_label_idx');
                $table->foreign('prompt_id')->references('id')->on('prompts')->cascadeOnDelete();
            });
        }

        // 5. prompt_results (Child of prompts)
        if (! $schema->hasTable('prompt_results')) {
            $schema->create('prompt_results', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('prompt_id')->index();
                $table->unsignedBigInteger('prompt_version_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('status', 32)->default('pending');
                $table->json('input_snapshot')->nullable();
                $table->longText('output_text')->nullable();
                $table->json('token_usage')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->string('canonical_prompt_key', 191)->nullable()->index();
                $table->string('stage', 191)->nullable();
                $table->char('compiled_prompt_hash', 64)->nullable();
                $table->unsignedBigInteger('content_project_id')->nullable()->index();
                $table->unsignedBigInteger('project_item_id')->nullable()->index();
                $table->unsignedBigInteger('run_id')->nullable()->index();
                $table->string('node_id', 120)->nullable();
                $table->unsignedInteger('retry_attempt')->nullable();
                $table->string('correlation_id', 191)->nullable()->index();
                $table->string('failure_category', 64)->nullable()->index();
                $table->string('failure_code', 128)->nullable();

                $table->foreign('prompt_id')->references('id')->on('prompts')->cascadeOnDelete();
            });
        }

        // 6. prompt_result_routing_attempts (Child of prompt_results)
        if (! $schema->hasTable('prompt_result_routing_attempts')) {
            $schema->create('prompt_result_routing_attempts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('prompt_result_id')->nullable()->index();
                $table->unsignedInteger('sequence')->default(1);
                $table->string('logical_model', 191)->nullable();
                $table->string('physical_route', 191)->nullable();
                $table->string('provider', 64)->nullable();
                $table->unsignedBigInteger('connection_id')->nullable()->index();
                $table->string('connection_name', 191)->nullable();
                $table->string('provider_model', 191)->nullable();
                $table->string('cost_class', 32)->nullable();
                $table->string('state', 32)->nullable();
                $table->boolean('attempted')->default(false);
                $table->string('skip_reason', 191)->nullable();
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->string('failure_category', 64)->nullable();
                $table->string('failure_code', 128)->nullable();
                $table->string('failure_scope', 64)->nullable();
                $table->string('health_mutation', 64)->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->json('token_usage')->nullable();
                $table->json('raw')->nullable();
                $table->timestamps();
                $table->string('addon', 32)->nullable()->index();
                $table->string('module', 64)->nullable()->index();
                $table->string('action', 64)->nullable()->index();
                $table->unsignedInteger('input_tokens')->nullable();
                $table->unsignedInteger('output_tokens')->nullable();
                $table->unsignedInteger('total_tokens')->nullable();

                $table->unique(['prompt_result_id', 'sequence'], 'pr_routing_attempts_result_seq_unique');
                $table->index(['created_at', 'addon', 'module'], 'pr_routing_attempts_time_addon_module_idx');
                $table->foreign('prompt_result_id')->references('id')->on('prompt_results')->cascadeOnDelete();
            });
        }

        // 7. Decouple cross-database foreign key on omi_seo_ai (if seo_prompt_result_links exists)
        try {
            if (Schema::connection('omi_seo_ai')->hasTable('seo_prompt_result_links')) {
                $fkMatches = DB::connection('omi_seo_ai')->select("
                    SELECT CONSTRAINT_NAME
                    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'seo_prompt_result_links'
                      AND CONSTRAINT_NAME = 'seo_prompt_result_links_prompt_result_id_foreign'
                ");
                if (! empty($fkMatches)) {
                    Schema::connection('omi_seo_ai')->table('seo_prompt_result_links', function (Blueprint $table): void {
                        $table->dropForeign('seo_prompt_result_links_prompt_result_id_foreign');
                    });
                }
            }
        } catch (\Throwable) {
            // Safe to ignore in environments without omi_seo_ai or foreign keys
        }

        // 8. Safely copy existing data from omi_seo_ai if present
        try {
            $migrationService = new PromptTaskCoreMigrationService();
            $migrationService->copy(execute: true);
        } catch (\Throwable) {
            // Log or let command verify handle discrepancies
        }
    }

    public function down(): void
    {
        $connection = (string) config('database.core_connection', config('database.default', 'mysql'));
        $schema = Schema::connection($connection);

        $schema->dropIfExists('prompt_result_routing_attempts');
        $schema->dropIfExists('prompt_results');
        $schema->dropIfExists('prompt_versions');
        $schema->dropIfExists('prompts');
        $schema->dropIfExists('task_test_results');
        $schema->dropIfExists('seo_tasks');
    }
};
