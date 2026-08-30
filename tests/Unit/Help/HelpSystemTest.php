<?php

declare(strict_types=1);

namespace Tests\Unit\Help;

use App\Help\HelpContextKeyBuilder;
use App\Help\HelpContextKeyRegistry;
use App\Help\HelpCoverageService;
use App\Help\HelpGroupRegistry;
use App\Help\HelpHtmlToMarkdownConverter;
use App\Help\HelpMarkdownDocument;
use App\Help\HelpMarkdownRenderer;
use App\Help\HelpPublishService;
use App\Help\HelpRemoteSyncService;
use App\Help\HelpRuntimePayloadBuilder;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class HelpSystemTest extends TestCase
{
    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cachePath = storage_path('framework/testing/help-cache-'.uniqid('', true));
        config(['help.cache.path' => $this->cachePath]);
        File::ensureDirectoryExists($this->cachePath);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cachePath)) {
            File::deleteDirectory($this->cachePath);
        }
        parent::tearDown();
    }

    public function test_parses_frontmatter_without_status(): void
    {
        $doc = new HelpMarkdownDocument();
        $md = <<<'MD'
---
key: domain.website_type
title: Website Type
summary: Hello
group: websites-domains
sort_order: 10
keywords:
  - shop
updated_at: '2026-08-30'
---

# Body
MD;
        $topic = $doc->toTopic($md, 'docs/websites-domains/x.md');
        self::assertSame('domain.website_type', $topic->key);
        self::assertSame('Hello', $topic->summary);
        self::assertSame('2026-08-30', $topic->updatedAt);

        $this->expectException(\InvalidArgumentException::class);
        $doc->toTopic(str_replace('title: Website Type', 'title: ', $md), 'docs/x.md');
    }

    public function test_detects_duplicate_context_keys_in_registry(): void
    {
        $keys = HelpContextKeyRegistry::keys();
        self::assertSame(count($keys), count(array_unique($keys)));
    }

    public function test_group_registry_orders_and_includes_empty_groups(): void
    {
        $groups = HelpGroupRegistry::all();
        self::assertNotEmpty($groups);
        $orders = array_column($groups, 'sort_order');
        $sorted = $orders;
        sort($sorted);
        self::assertSame($sorted, $orders);
        self::assertSame('domain', HelpGroupRegistry::contextPrefix('websites-domains'));
    }

    public function test_group_sort_order_override_reorders_shared_registry(): void
    {
        app(HelpRemoteSyncService::class)->rebuildFromLocalDirectory(
            base_path('resources/help-seed'),
            '2026.08.30.group-order',
        );

        $cache = app(\App\Help\HelpCacheStore::class);
        $cache->writeGroupSortOrders([
            'websites-domains' => 5,
            'getting-started' => 500,
            'keywords-topics' => 90,
            'article-editor' => 70,
        ]);

        $ids = array_column(HelpGroupRegistry::all(), 'id');
        self::assertLessThan(
            (int) array_search('getting-started', $ids, true),
            (int) array_search('websites-domains', $ids, true),
        );

        $payload = app(HelpRuntimePayloadBuilder::class)->clientPayload(attemptSync: false);
        $payloadIds = array_column($payload['groups'], 'id');
        self::assertContains('websites-domains', $payloadIds);
        self::assertLessThan(
            (int) array_search('getting-started', $payloadIds, true) ?: PHP_INT_MAX,
            (int) array_search('websites-domains', $payloadIds, true),
        );
    }

    public function test_local_repo_sync_prefers_sibling_docs_when_enabled(): void
    {
        $tmp = storage_path('framework/testing/help-local-'.uniqid('', true));
        File::ensureDirectoryExists($tmp.'/docs/websites-domains');
        file_put_contents($tmp.'/VERSION', "local-test\n");
        file_put_contents($tmp.'/docs/websites-domains/website-type.md', <<<'MD'
---
key: domain.website_type
title: Website Type
summary: From local repo
group: websites-domains
sort_order: 10
keywords: []
updated_at: '2026-08-30'
---

# Local body
MD);

        config([
            'help.local.enabled' => true,
            'help.local.path' => $tmp,
            'app.env' => 'local',
        ]);

        $result = app(HelpRemoteSyncService::class)->sync(force: true);
        self::assertTrue($result['ok']);
        self::assertSame('local-repo', $result['source'] ?? null);

        $payload = app(HelpRuntimePayloadBuilder::class)->clientPayload(attemptSync: false);
        self::assertSame('local-repo+legacy', $payload['source']);
        self::assertArrayHasKey('domain.website_type', $payload['topic_by_key']);

        $found = false;
        foreach ($payload['groups'] as $group) {
            foreach ($group['topics'] ?? [] as $topic) {
                if (($topic['id'] ?? '') === 'domain.website_type' || ($topic['key'] ?? '') === 'domain.website_type') {
                    self::assertSame('From local repo', $topic['summary'] ?? null);
                    $found = true;
                }
            }
        }
        self::assertTrue($found);

        File::deleteDirectory($tmp);
    }

    public function test_local_seed_rebuild_and_coverage(): void
    {
        $seed = base_path('resources/help-seed');
        self::assertDirectoryExists($seed);

        $sync = app(HelpRemoteSyncService::class);
        $result = $sync->rebuildFromLocalDirectory($seed, '2026.08.30.1');
        self::assertTrue($result['ok']);
        self::assertGreaterThanOrEqual(4, $result['topic_count']);

        $coverage = app(HelpCoverageService::class)->analyze();
        self::assertContains('domain.website_type', $coverage['covered']);
        self::assertContains('topic.lock', $coverage['covered']);
        self::assertArrayNotHasKey('draft', $coverage);
        self::assertNotEmpty($coverage['missing']);

        $payload = app(HelpRuntimePayloadBuilder::class)->clientPayload(attemptSync: false);
        self::assertTrue(in_array($payload['source'], ['git-cache+legacy', 'local-repo+legacy'], true));
        self::assertArrayHasKey('domain.website_type', $payload['topic_by_key']);
        self::assertNotEmpty($payload['groups']);
    }

    public function test_topic_requires_content(): void
    {
        $tmp = storage_path('framework/testing/help-empty-'.uniqid('', true));
        File::ensureDirectoryExists($tmp.'/docs/g');
        file_put_contents($tmp.'/docs/g/empty.md', <<<'MD'
---
key: empty.body
title: Empty
summary: Sum
group: getting-started
sort_order: 1
keywords: []
updated_at: '2026-08-30'
---

MD);

        $this->expectException(\InvalidArgumentException::class);
        app(HelpRemoteSyncService::class)->rebuildFromLocalDirectory($tmp, 'empty');
    }

    public function test_html_markdown_round_trip_preserves_structure(): void
    {
        $markdown = <<<'MD'
# Intro

Hello **world** and a [link](https://example.com).

## When to use

- One
- Two

| A | B |
| --- | --- |
| 1 | 2 |

![shot](images/demo.png)
MD;
        $html = (new HelpMarkdownRenderer())->toHtml($markdown);
        $back = (new HelpHtmlToMarkdownConverter())->convert($html);

        self::assertStringContainsString('**world**', $back);
        self::assertStringContainsString('[link](https://example.com)', $back);
        self::assertStringContainsString('- One', $back);
        self::assertStringContainsString('![shot](images/demo.png)', $back);
        self::assertMatchesRegularExpression('/##?\s*When to use/i', $back);
    }

    public function test_publish_path_slug_uses_key_leaf(): void
    {
        $ref = new \ReflectionClass(HelpPublishService::class);
        $method = $ref->getMethod('pathSlug');
        $method->setAccessible(true);
        $svc = app(HelpPublishService::class);
        self::assertSame('website-type', $method->invoke($svc, 'domain.website_type'));
        self::assertSame('focus-article', $method->invoke($svc, 'keyword.focus_article'));
    }

    public function test_rejects_duplicate_keys_on_rebuild(): void
    {
        $tmp = storage_path('framework/testing/help-dup-'.uniqid('', true));
        File::ensureDirectoryExists($tmp.'/docs/g');
        $body = <<<'MD'
---
key: dup.key
title: A
summary: S
group: getting-started
sort_order: 1
keywords: []
updated_at: '2026-08-30'
---

Body
MD;
        file_put_contents($tmp.'/docs/g/a.md', $body);
        file_put_contents($tmp.'/docs/g/b.md', $body);

        $this->expectException(\InvalidArgumentException::class);
        app(HelpRemoteSyncService::class)->rebuildFromLocalDirectory($tmp, 'dup');
    }

    public function test_formats_unix_updated_at_for_admin(): void
    {
        self::assertSame('30/08/2026', HelpContextKeyBuilder::formatUpdatedAtForAdmin('2026-08-30'));
        self::assertSame('30/08/2026 16:45', HelpContextKeyBuilder::formatUpdatedAtForAdmin('2026-08-30 16:45'));
        self::assertSame(
            HelpContextKeyBuilder::formatUpdatedAtForAdmin(date('Y-m-d', 1788048000)),
            HelpContextKeyBuilder::formatUpdatedAtForAdmin(1788048000),
        );
        self::assertSame('domain.website_type', HelpContextKeyBuilder::fromGroupAndTitle('websites-domains', 'Website Type'));
    }

    public function test_article_editor_help_topics_are_covered(): void
    {
        $expectedKeys = [
            'article_editor.panel.seo',
            'article_editor.panel.images',
            'article_editor.panel.featured',
            'article_editor.panel.links',
            'article_editor.panel.cta',
            'article_editor.panel.vocabulary',
            'article_editor.panel.publishing',
            'article_editor.widget.live_score',
            'article_editor.widget.focus_keyword',
            'article_editor.widget.title_length',
            'article_editor.widget.image_count',
            'article_editor.widget.external_links',
            'article_editor.widget.featured_snippet_table',
            'article_editor.widget.google_preview',
            'article_editor.widget.outline',
            'article_editor.widget.find_replace',
            'article_editor.widget.editor_toolbar',
            'article_editor.widget.section_blocks',
            'article_editor.widget.html_mode',
        ];

        foreach ($expectedKeys as $key) {
            self::assertTrue(HelpContextKeyRegistry::has($key), 'missing registry key: '.$key);
        }

        $seed = base_path('resources/help-seed');
        $result = app(HelpRemoteSyncService::class)->rebuildFromLocalDirectory($seed, '2026.08.30.article');
        self::assertTrue($result['ok']);

        $coverage = app(HelpCoverageService::class)->analyze();
        foreach ($expectedKeys as $key) {
            self::assertContains($key, $coverage['covered'], 'not covered: '.$key);
        }

        $doc = new HelpMarkdownDocument();
        $sample = (string) file_get_contents($seed.'/docs/article-editor-widgets/seo-panel.md');
        self::assertStringNotContainsString("\nstatus:", $sample);
        self::assertStringNotContainsString('video_url', $sample);
        self::assertStringNotContainsString('related_topics', $sample);
        self::assertStringNotContainsString("\nrelated:", $sample);
        $topic = $doc->toTopic($sample, 'docs/article-editor-widgets/seo-panel.md');
        self::assertSame('article-editor', $topic->group);
        self::assertSame(100, $topic->sortOrder);
        self::assertStringContainsString('<!-- Admin sẽ chèn', $topic->bodyMarkdown);
    }

    public function test_yaml_date_timestamp_normalized_on_parse(): void
    {
        $doc = new HelpMarkdownDocument();
        // Unquoted YAML date often becomes unix int via Symfony Yaml
        $md = <<<'MD'
---
key: domain.platform
title: Platform
summary: Sum
group: websites-domains
sort_order: 1
keywords: []
updated_at: 2026-08-30
---

Body here
MD;
        $topic = $doc->toTopic($md, 'docs/websites-domains/platform.md');
        self::assertNotNull($topic->updatedAt);
        self::assertDoesNotMatchRegularExpression('/^\d{9,}$/', (string) $topic->updatedAt);
        self::assertStringNotContainsString('1788', HelpContextKeyBuilder::formatUpdatedAtForAdmin($topic->updatedAt));
    }
}
