<?php

declare(strict_types=1);

namespace Tests\Unit\ClientTransfer;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

abstract class TransferDatabaseTestCase extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'transfer_db_test_'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);

        $dbFile = $this->tempDir.DIRECTORY_SEPARATOR.'test.sqlite';
        touch($dbFile);

        config()->set('database.connections.sqlite.database', $dbFile);
        config()->set('database.connections.omi_seo_ai', array_merge(
            config('database.connections.sqlite'),
            ['database' => $dbFile]
        ));

        DB::purge('sqlite');
        DB::purge('omi_seo_ai');

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    protected function createSchema(): void
    {
        Schema::dropIfExists('services');
        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('addon_namespace')->nullable();
            $table->string('db_connection')->default('mysql');
            $table->boolean('is_active')->default(true);
            $table->json('config')->nullable();
            $table->text('service_key')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('role')->default('staff');
            $table->string('status')->default('normal');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::dropIfExists('sites');
        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('domain')->unique();
            $table->string('status')->default('active');
            $table->boolean('ssl')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::dropIfExists('site_meta');
        Schema::create('site_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('meta_key')->index();
            $table->longText('meta_value')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('site_services');
        Schema::create('site_services', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('service_id');
            $table->string('bound_type')->default('site');
            $table->string('status')->default('active');
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('keywords');
        Schema::create('keywords', function (Blueprint $table): void {
            $table->id();
            $table->string('phrase')->unique('keywords_phrase_unique');
            $table->string('type')->default('normal');
            $table->string('source')->nullable();
            $table->boolean('source_locked')->default(false);
            $table->string('review_status')->nullable();
            $table->unsignedBigInteger('review_reason_id')->nullable();
            $table->text('review_note')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('keyword_meta');
        Schema::create('keyword_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
            $table->unique(['keyword_id', 'meta_key']);
        });

        Schema::dropIfExists('seo_site_keywords');
        Schema::create('seo_site_keywords', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('keyword_id');
            $table->string('phrase_kind')->nullable();
            $table->string('seo_intent')->nullable();
            $table->boolean('is_seo_keyword')->default(false);
            $table->boolean('is_anchor_candidate')->default(false);
            $table->boolean('is_ambiguous')->default(false);
            $table->decimal('keyword_score', 8, 2)->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->string('review_state')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('seo_topics');
        Schema::create('seo_topics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('name');
            $table->string('source')->nullable();
            $table->string('status')->default('active');
            $table->boolean('is_locked')->default(false);
            $table->boolean('mcp_excluded')->default(false);
            $table->timestamps();
        });

        Schema::dropIfExists('seo_topic_tags');
        Schema::create('seo_topic_tags', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('name');
            $table->string('slug')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::dropIfExists('seo_topic_keywords');
        Schema::create('seo_topic_keywords', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('topic_id');
            $table->unsignedBigInteger('keyword_id');
            $table->string('source')->nullable();
            $table->boolean('is_seed')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->decimal('confidence', 5, 2)->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('seo_topic_tag_assignments');
        Schema::create('seo_topic_tag_assignments', function (Blueprint $table): void {
            $table->unsignedBigInteger('topic_id');
            $table->unsignedBigInteger('tag_id');
            $table->string('source')->default('manual');
            $table->timestamp('created_at')->nullable();
            $table->primary(['topic_id', 'tag_id']);
        });

        Schema::dropIfExists('seo_topic_keyword_dna');
        Schema::create('seo_topic_keyword_dna', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('topic_id');
            $table->unsignedBigInteger('keyword_id');
            $table->text('value')->nullable();
            $table->string('facet_type')->nullable();
            $table->string('placement')->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->string('source')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('articles');
        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('title');
            $table->string('slug')->nullable();
            $table->string('language', 10)->nullable();
            $table->string('status', 32)->default('draft');
            $table->text('excerpt')->nullable();
            $table->longText('body')->nullable();
            $table->json('blocks')->nullable();
            $table->json('editor_document')->nullable();
            $table->integer('document_version')->default(1);
            $table->integer('editor_document_schema_version')->nullable();
            $table->dateTime('editor_document_updated_at')->nullable();
            $table->string('review_status', 32)->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('last_manual_saved_at')->nullable();
            $table->dateTime('last_ai_content_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::dropIfExists('seo_article_profiles');
        Schema::create('seo_article_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->unique()->constrained('articles')->cascadeOnDelete();
            $table->decimal('seo_score', 5, 2)->nullable();
            $table->boolean('skip_seo_score')->default(false);
            $table->unsignedInteger('internal_link_count')->default(0);
            $table->unsignedInteger('external_link_count')->default(0);
            $table->dateTime('indexed_at')->nullable();
            $table->dateTime('previous_indexed_at')->nullable();
            $table->string('focus_keyword')->nullable();
            $table->string('canonical_url', 500)->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('article_meta');
        Schema::create('article_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('seo_article_headings');
        Schema::create('seo_article_headings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->integer('level')->default(1);
            $table->text('heading_text');
            $table->string('heading_slug');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::dropIfExists('seo_faqs');
        Schema::create('seo_faqs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->text('question');
            $table->longText('answer');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::dropIfExists('seo_article_reviews');
        Schema::create('seo_article_reviews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->string('action_type');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->unsignedBigInteger('reviewer_id');
            $table->string('reviewer_role')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('seo_site_manual_links');
        Schema::create('seo_site_manual_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('keyword');
            $table->text('url');
            $table->string('url_hash', 64)->nullable();
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
        });

        Schema::dropIfExists('seo_site_link_exclusions');
        Schema::create('seo_site_link_exclusions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->text('url');
            $table->string('url_hash', 64)->nullable();
            $table->unsignedBigInteger('wordpress_id')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('seo_link_maps');
        Schema::create('seo_link_maps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id')->nullable();
            $table->unsignedBigInteger('source_article_id')->nullable();
            $table->unsignedBigInteger('target_article_id')->nullable();
            $table->unsignedBigInteger('target_site_id')->nullable();
            $table->text('target_external_url')->nullable();
            $table->text('anchor_text')->nullable();
            $table->text('context_before')->nullable();
            $table->text('context_after')->nullable();
            $table->string('link_type')->default('internal');
            $table->string('status')->default('active');
            $table->string('destination_kind')->default('article');
            $table->boolean('is_semantic_eligible')->default(true);
            $table->integer('last_http_status')->nullable();
            $table->dateTime('last_audited_at')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('seo_projects');
        Schema::create('seo_projects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('source_draft_project_id')->nullable();
            $table->string('name');
            $table->date('month')->nullable();
            $table->string('status')->default('draft');
            $table->string('kind')->default('monthly');
            $table->integer('total_tasks')->default(0);
            $table->json('meta')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('seo_project_tasks');
        Schema::create('seo_project_tasks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('article_id')->nullable();
            $table->unsignedBigInteger('archived_from_project_id')->nullable();
            $table->string('source_content', 500);
            $table->string('keyword')->nullable();
            $table->string('title')->nullable();
            $table->string('type')->default('create');
            $table->string('status')->default('pending');
            $table->date('target_date')->nullable();
            $table->dateTime('scheduled_publish_at')->nullable();
            $table->dateTime('content_manager_reviewed_at')->nullable();
            $table->unsignedBigInteger('content_manager_reviewed_by')->nullable();
            $table->dateTime('planning_reviewed_at')->nullable();
            $table->unsignedBigInteger('planning_reviewed_by')->nullable();
            $table->dateTime('publishing_queued_at')->nullable();
            $table->unsignedBigInteger('publishing_queued_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::dropIfExists('wordpress_article_links');
        Schema::create('wordpress_article_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('wp_post_id')->default(0);
            $table->integer('last_seen_sync_generation')->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->dateTime('external_modified_at')->nullable();
            $table->dateTime('observed_modified_at')->nullable();
            $table->dateTime('observed_at')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('publishing_article_states');
        Schema::create('publishing_article_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->string('platform', 32)->default('primary');
            $table->string('publication_status', 32)->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('last_attempt_at')->nullable();
            $table->string('last_attempt_ref')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('seo_media');
        Schema::create('seo_media', function (Blueprint $table): void {
            $table->id();
            $table->string('filename');
            $table->string('slug');
            $table->string('path');
            $table->string('url');
            $table->string('source')->default('clipboard');
            $table->timestamps();
        });

        Schema::dropIfExists('seo_media_meta');
        Schema::create('seo_media_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('media_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
            $table->unique(['media_id', 'meta_key']);
        });

        Schema::dropIfExists('client_transfer_runs');
        Schema::create('client_transfer_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id', 64)->unique();
            $table->string('type', 20);
            $table->string('status', 30)->default('pending');
            $table->string('phase', 50)->default('queued');
            $table->string('current_dataset', 100)->nullable();
            $table->unsignedInteger('current_part')->nullable();
            $table->unsignedBigInteger('record_offset')->default(0);
            $table->unsignedBigInteger('total_records')->default(0);
            $table->unsignedBigInteger('processed_records')->default(0);
            $table->unsignedBigInteger('imported_count')->default(0);
            $table->unsignedBigInteger('failed_count')->default(0);
            $table->unsignedBigInteger('blocked_count')->default(0);
            $table->unsignedBigInteger('warnings_count')->default(0);
            $table->unsignedBigInteger('missing_refs_count')->default(0);
            $table->text('artifact_path')->nullable();
            $table->text('retry_package_path')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    protected function wipeBusinessTables(): void
    {
        $tables = [
            'articles', 'seo_article_profiles', 'article_meta', 'seo_article_headings', 'seo_faqs', 'seo_article_reviews',
            'keywords', 'keyword_meta', 'seo_site_keywords', 'seo_topics', 'seo_topic_tags',
            'seo_topic_keywords', 'seo_topic_tag_assignments', 'seo_topic_keyword_dna',
            'seo_site_manual_links', 'seo_site_link_exclusions', 'seo_link_maps',
            'seo_projects', 'seo_project_tasks', 'wordpress_article_links',
            'publishing_article_states', 'seo_media', 'seo_media_meta',
        ];

        foreach ($tables as $t) {
            DB::table($t)->truncate();
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $files = scandir($dir);
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $p = $dir.DIRECTORY_SEPARATOR.$file;
            is_dir($p) ? $this->deleteDirectory($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
