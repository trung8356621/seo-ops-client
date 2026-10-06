<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer;

use App\Services\ClientTransfer\Manifest\TransferManifest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class TargetSchemaValidator
{
    /**
     * @var array<string, list<array{connection: string, table: string, columns: list<string>, portable?: bool}>>
     */
    private const CONTRACTS = [
        'users' => [
            ['connection' => 'default', 'table' => 'users', 'columns' => ['id', 'parent_id', 'name', 'email', 'password', 'role', 'status', 'is_system', 'created_at', 'updated_at']],
        ],
        'sites' => [
            ['connection' => 'default', 'table' => 'sites', 'columns' => ['id', 'user_id', 'domain', 'status', 'ssl', 'created_at', 'updated_at']],
            ['connection' => 'default', 'table' => 'site_meta', 'columns' => ['id', 'site_id', 'meta_key', 'meta_value', 'created_at', 'updated_at']],
        ],
        'site_services' => [
            ['connection' => 'default', 'table' => 'services', 'columns' => ['id', 'slug']],
            ['connection' => 'default', 'table' => 'site_services', 'columns' => ['id', 'site_id', 'user_id', 'service_id', 'bound_type', 'status', 'settings', 'created_at', 'updated_at']],
        ],
        'keywords' => [
            ['connection' => 'omi_seo_ai', 'table' => 'keywords', 'columns' => ['id', 'phrase', 'type', 'source', 'source_locked', 'review_status', 'review_note', 'reviewed_by', 'reviewed_at', 'created_at', 'updated_at'], 'portable' => true],
            ['connection' => 'omi_seo_ai', 'table' => 'keyword_meta', 'columns' => ['id', 'keyword_id', 'meta_key', 'meta_value', 'created_at', 'updated_at'], 'portable' => true],
        ],
        'site_keywords' => [
            ['connection' => 'omi_seo_ai', 'table' => 'seo_site_keywords', 'columns' => ['id', 'site_id', 'keyword_id', 'phrase_kind', 'seo_intent', 'is_seo_keyword', 'is_anchor_candidate', 'is_ambiguous', 'keyword_score', 'confidence', 'review_state', 'source', 'created_at', 'updated_at'], 'portable' => true],
        ],
        'topics' => [
            ['connection' => 'omi_seo_ai', 'table' => 'seo_topics', 'columns' => ['id', 'site_id', 'name', 'source', 'status', 'is_locked', 'mcp_excluded', 'created_at', 'updated_at'], 'portable' => true],
        ],
        'topic_tags' => [
            ['connection' => 'omi_seo_ai', 'table' => 'seo_topic_tags', 'columns' => ['id', 'site_id', 'name', 'slug', 'created_at'], 'portable' => true],
        ],
        'topic_keywords' => [
            ['connection' => 'omi_seo_ai', 'table' => 'seo_topic_keywords', 'columns' => ['id', 'site_id', 'topic_id', 'keyword_id', 'source', 'is_seed', 'is_locked', 'confidence', 'created_at', 'updated_at'], 'portable' => true],
        ],
        'topic_tag_assignments' => [
            ['connection' => 'omi_seo_ai', 'table' => 'seo_topic_tag_assignments', 'columns' => ['topic_id', 'tag_id', 'source', 'created_at'], 'portable' => true],
        ],
        'topic_keyword_dna' => [
            ['connection' => 'omi_seo_ai', 'table' => 'seo_topic_keyword_dna', 'columns' => ['id', 'site_id', 'topic_id', 'keyword_id', 'value', 'facet_type', 'placement', 'confidence', 'source', 'created_at', 'updated_at'], 'portable' => true],
        ],
        'articles' => [
            ['connection' => 'omi_seo_ai', 'table' => 'articles', 'columns' => ['id', 'site_id', 'user_id', 'title', 'slug', 'language', 'status', 'excerpt', 'body', 'editor_document', 'blocks', 'document_version', 'editor_document_schema_version', 'editor_document_updated_at', 'review_status', 'reviewed_at', 'last_manual_saved_at', 'last_ai_content_at', 'created_at', 'updated_at', 'deleted_at'], 'portable' => true],
            ['connection' => 'omi_seo_ai', 'table' => 'seo_article_profiles', 'columns' => ['id', 'article_id', 'focus_keyword', 'canonical_url', 'created_at', 'updated_at'], 'portable' => true],
        ],
        'article_meta' => [
            ['connection' => 'omi_seo_ai', 'table' => 'article_meta', 'columns' => ['id', 'article_id', 'meta_key', 'meta_value', 'created_at', 'updated_at'], 'portable' => true],
        ],
        'article_headings' => [
            ['connection' => 'omi_seo_ai', 'table' => 'seo_article_headings', 'columns' => ['id', 'article_id', 'parent_id', 'level', 'heading_text', 'heading_slug', 'sort_order', 'created_at', 'updated_at'], 'portable' => true],
        ],
        'article_faqs' => [
            ['connection' => 'omi_seo_ai', 'table' => 'seo_faqs', 'columns' => ['id', 'article_id', 'question', 'answer', 'sort_order', 'created_at', 'updated_at'], 'portable' => true],
        ],
        'article_reviews' => [
            ['connection' => 'omi_seo_ai', 'table' => 'seo_article_reviews', 'columns' => ['id', 'article_id', 'action_type', 'from_status', 'to_status', 'reviewer_id', 'reviewer_role', 'note', 'created_at', 'updated_at'], 'portable' => true],
        ],
        'manual_links' => [
            ['connection' => 'omi_seo_ai', 'table' => 'seo_site_manual_links', 'columns' => ['id', 'site_id', 'keyword', 'url', 'url_hash', 'is_locked', 'created_at', 'updated_at'], 'portable' => true],
        ],
        'link_exclusions' => [
            ['connection' => 'omi_seo_ai', 'table' => 'seo_site_link_exclusions', 'columns' => ['id', 'site_id', 'url', 'url_hash', 'wordpress_id', 'reason', 'created_at', 'updated_at'], 'portable' => true],
        ],
        'link_maps' => [
            ['connection' => 'omi_seo_ai', 'table' => 'seo_link_maps', 'columns' => ['id', 'keyword_id', 'source_article_id', 'target_article_id', 'target_site_id', 'target_external_url', 'anchor_text', 'context_before', 'context_after', 'link_type', 'status', 'destination_kind', 'is_semantic_eligible', 'last_http_status', 'last_audited_at', 'created_at', 'updated_at'], 'portable' => true],
        ],
        'content_projects' => [
            ['connection' => 'omi_seo_ai', 'table' => 'seo_projects', 'columns' => ['id', 'site_id', 'user_id', 'source_draft_project_id', 'name', 'month', 'status', 'kind', 'total_tasks', 'meta', 'archived_at', 'archived_by', 'created_at', 'updated_at'], 'portable' => true],
        ],
        'content_project_tasks' => [
            ['connection' => 'omi_seo_ai', 'table' => 'seo_project_tasks', 'columns' => ['id', 'project_id', 'site_id', 'article_id', 'archived_from_project_id', 'source_content', 'keyword', 'title', 'type', 'status', 'target_date', 'scheduled_publish_at', 'content_manager_reviewed_at', 'content_manager_reviewed_by', 'planning_reviewed_at', 'planning_reviewed_by', 'publishing_queued_at', 'publishing_queued_by', 'created_at', 'updated_at', 'deleted_at'], 'portable' => true],
        ],
        'wordpress_article_links' => [
            ['connection' => 'omi_seo_ai', 'table' => 'wordpress_article_links', 'columns' => ['id', 'article_id', 'site_id', 'wp_post_id', 'last_seen_sync_generation', 'last_synced_at', 'external_modified_at', 'observed_modified_at', 'observed_at', 'created_at', 'updated_at'], 'portable' => true],
        ],
        'publishing_article_states' => [
            ['connection' => 'omi_seo_ai', 'table' => 'publishing_article_states', 'columns' => ['id', 'article_id', 'platform', 'publication_status', 'published_at', 'created_at', 'updated_at'], 'portable' => true],
        ],
        'media' => [
            ['connection' => 'omi_seo_ai', 'table' => 'seo_media', 'columns' => ['id', 'filename', 'slug', 'path', 'url', 'source', 'created_at', 'updated_at'], 'portable' => true],
            ['connection' => 'omi_seo_ai', 'table' => 'seo_media_meta', 'columns' => ['id', 'media_id', 'meta_key', 'meta_value', 'created_at', 'updated_at'], 'portable' => true],
        ],
    ];

    /**
     * @return list<string>
     */
    public function validate(TransferManifest $manifest): array
    {
        $errors = [];
        foreach (array_keys($manifest->datasets) as $datasetKey) {
            $contracts = self::CONTRACTS[$datasetKey] ?? null;
            if ($contracts === null) {
                $errors[] = "Unknown dataset: {$datasetKey}";

                continue;
            }

            foreach ($contracts as $contract) {
                $connection = $this->resolveConnection($contract['connection']);
                try {
                    $schema = Schema::connection($connection);
                    if (! $schema->hasTable($contract['table'])) {
                        $errors[] = 'Missing table: '.$contract['table'];

                        continue;
                    }

                    $available = array_fill_keys($schema->getColumnListing($contract['table']), true);
                    foreach ($contract['columns'] as $column) {
                        if (! isset($available[$column])) {
                            $errors[] = 'Missing column: '.$contract['table'].'.'.$column;
                        }
                    }
                } catch (\Throwable $e) {
                    $errors[] = 'Schema inspection failed for '.$contract['table'].': '.$e->getMessage();
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @return list<array{connection: string, table: string}>
     */
    public function portableTables(TransferManifest $manifest): array
    {
        $tables = [];
        foreach (array_keys($manifest->datasets) as $datasetKey) {
            foreach (self::CONTRACTS[$datasetKey] ?? [] as $contract) {
                if (! ($contract['portable'] ?? false)) {
                    continue;
                }
                $key = $contract['connection'].':'.$contract['table'];
                $tables[$key] = [
                    'connection' => $this->resolveConnection($contract['connection']),
                    'table' => $contract['table'],
                ];
            }
        }

        return array_values($tables);
    }

    /** @return list<string> */
    public function validatePortableSchema(): array
    {
        $errors = [];
        foreach ($this->allPortableTargets() as $target) {
            $schema = Schema::connection($target['connection']);
            if (! $schema->hasTable($target['table'])) {
                $errors[] = 'Missing table: '.$target['table'];

                continue;
            }
            $available = array_fill_keys($schema->getColumnListing($target['table']), true);
            foreach ($target['columns'] as $column) {
                if (! isset($available[$column])) {
                    $errors[] = 'Missing column: '.$target['table'].'.'.$column;
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /** @return array<string, int> */
    public function nonEmptyPortableTables(): array
    {
        $nonEmpty = [];
        foreach ($this->allPortableTargets() as $target) {
            $count = DB::connection($target['connection'])->table($target['table'])->count();
            if ($count > 0) {
                $nonEmpty[$target['table']] = $count;
            }
        }

        return $nonEmpty;
    }

    /** @return list<array{connection: string, table: string, columns: list<string>}> */
    private function allPortableTargets(): array
    {
        $targets = [];
        foreach (self::CONTRACTS as $contracts) {
            foreach ($contracts as $contract) {
                if (! ($contract['portable'] ?? false)) {
                    continue;
                }
                $key = $contract['connection'].':'.$contract['table'];
                $targets[$key] = [
                    'connection' => $this->resolveConnection($contract['connection']),
                    'table' => $contract['table'],
                    'columns' => $contract['columns'],
                ];
            }
        }

        return array_values($targets);
    }

    private function resolveConnection(string $connection): string
    {
        return $connection === 'default' ? (string) config('database.default') : $connection;
    }
}
