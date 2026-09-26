<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Api\Access\TemporaryServiceAccessManager;
use App\Api\Auth\ServiceApiCredentialManager;
use App\Models\Service;
use App\Models\Site;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Seo\Services\Access\SeoAccessCatalog;
use Omnichannel\Addons\Seo\Services\Access\SeoAccessContentComposer;
use Omnichannel\Addons\Seo\Services\Access\SeoAccessGscComposer;
use Omnichannel\Addons\Seo\Services\Access\SeoAccessKeywordsComposer;
use Omnichannel\Addons\Seo\Services\Access\SeoAccessSiteKnowledgeComposer;
use Omnichannel\Addons\Seo\Services\GscContext\Dto\GscContext;
use Omnichannel\Addons\Seo\Services\GscContext\GscContextLoader;
use Omnichannel\Addons\Seo\Services\GscContext\GscContextSource;
use Tests\TestCase;
use Tests\Unit\Api\UsesServiceApiCredentialSchema;

final class SeoAccessHttpTest extends TestCase
{
    use UsesServiceApiCredentialSchema;

    private Service $seo;

    private string $readKey = '';

    private int $credentialId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootServiceApiCredentialSchema();
        $this->bootSitesSchema();
        $this->bindStubAccessStack();
        Cache::flush();

        $this->seo = $this->makeService('SEO', 'seo');
        $created = app(ServiceApiCredentialManager::class)->create(
            $this->seo,
            'SEO Read',
            ['seo:read'],
        );
        $this->readKey = $created->rawKey;
        $this->credentialId = (int) $created->credential->id;

        Site::query()->forceCreate([
            'id' => 7,
            'user_id' => 1,
            'domain' => 'example.com',
            'status' => 'active',
            'ssl' => true,
        ]);
        Site::query()->forceCreate([
            'id' => 456,
            'user_id' => 1,
            'domain' => 'other.test',
            'status' => 'active',
            'ssl' => true,
        ]);
    }

    public function test_service_access_index_requires_seo_read_and_lists_sites(): void
    {
        $this->getJson('/api/v1/services/seo/access')
            ->assertStatus(401);

        $noScope = app(ServiceApiCredentialManager::class)->create($this->seo, 'S', ['service:read']);
        $this->getJson('/api/v1/services/seo/access', [
            'Authorization' => 'Bearer '.$noScope->rawKey,
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'service_api_scope_denied');

        $mcpOnly = app(ServiceApiCredentialManager::class)->create($this->seo, 'M', ['mcp:read']);
        $this->getJson('/api/v1/services/seo/access', [
            'Authorization' => 'Bearer '.$mcpOnly->rawKey,
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'service_api_scope_denied');

        $seed = $this->makeService('Seeding', 'seeding');
        $seedKey = app(ServiceApiCredentialManager::class)->create($seed, 'Seed', ['seo:read']);
        $this->getJson('/api/v1/services/seo/access', [
            'Authorization' => 'Bearer '.$seedKey->rawKey,
        ])->assertStatus(403);

        $response = $this->getJson('/api/v1/services/seo/access', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.service', 'seo')
            ->assertJsonPath('data.access.method', 'POST')
            ->assertJsonPath('data.access.href', '/api/v1/services/seo/access');

        $sites = $response->json('data.sites');
        self::assertIsArray($sites);
        self::assertNotEmpty($sites);
        self::assertSame('site:7', $sites[0]['site_ref']);
        self::assertSame('example.com', $sites[0]['domain']);
        self::assertStringNotContainsString('svc_live_', (string) $response->getContent());
        self::assertStringNotContainsString($this->readKey, (string) $response->getContent());
    }

    public function test_mint_returns_temporary_access_url_and_cache_payload(): void
    {
        $this->postJson('/api/v1/services/seo/access', ['site_id' => 7])
            ->assertStatus(401);

        $response = $this->postJson('/api/v1/services/seo/access', [
            'site_id' => 7,
        ], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.site_ref', 'site:7');

        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $accessUrl = (string) $response->json('data.access_url');
        self::assertStringContainsString('/api/v1/access/access_tmp_', $accessUrl);
        self::assertNotEmpty($response->json('data.expires_at'));
        self::assertStringNotContainsString($this->readKey, (string) $response->getContent());

        $token = $this->tokenFromAccessUrl($accessUrl);
        $manager = app(TemporaryServiceAccessManager::class);
        $lookup = $manager->extractLookupId($token);
        self::assertNotNull($lookup);

        $payload = Cache::get($manager->cacheKey($lookup));
        self::assertIsArray($payload);
        self::assertSame((int) $this->seo->id, (int) $payload['service_id']);
        self::assertSame($this->credentialId, (int) $payload['credential_id']);
        self::assertSame(7, (int) $payload['site_id']);
        self::assertSame(['seo:read'], $payload['scopes']);
        self::assertArrayHasKey('token_hash', $payload);
        self::assertStringNotContainsString($token, json_encode($payload) ?: '');

        $dbDump = json_encode(DB::table('service_api_credentials')->get()->all()) ?: '';
        self::assertStringNotContainsString($token, $dbDump);
        self::assertStringNotContainsString('access_tmp_', $dbDump);

        $this->postJson('/api/v1/services/seo/access', ['site_id' => 0], $this->auth())
            ->assertStatus(422);
        $this->postJson('/api/v1/services/seo/access', ['site_id' => 999999], $this->auth())
            ->assertStatus(422);
        $this->postJson('/api/v1/services/seo/access', [
            'site_id' => 7,
            'ttl' => 60,
        ], $this->auth())->assertStatus(422);
    }

    public function test_temporary_root_exposes_only_canonical_resources(): void
    {
        $token = $this->mintToken(7);
        $root = '/api/v1/access/'.$token;

        $response = $this->getJson($root)
            ->assertOk()
            ->assertJsonPath('data.schema', SeoAccessCatalog::SCHEMA)
            ->assertJsonPath('data.site_ref', 'site:7')
            ->assertJsonPath('data.site.domain', 'example.com');

        $keys = array_column($response->json('data.resources'), 'key');
        self::assertSame(['site', 'content', 'keywords', 'gsc'], $keys);

        $body = (string) $response->getContent();
        foreach ([
            'mcp', 'router', 'parts', 'indexability', 'inventory',
            'publishing', 'findings', 'ContextRegistry', 'McpRouter',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $body);
        }

        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringNotContainsString($this->readKey, $body);

        $this->getJson('/api/v1/access/access_tmp_deadbeef_notreal')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'service_api_temporary_access_invalid');
    }

    public function test_site_resource_returns_knowledge_without_indexability(): void
    {
        $token = $this->mintToken(7);
        $response = $this->getJson('/api/v1/access/'.$token.'/site')
            ->assertOk()
            ->assertJsonPath('data.schema', SeoAccessSiteKnowledgeComposer::SCHEMA)
            ->assertJsonPath('data.site_ref', 'site:7')
            ->assertJsonPath('data.identity.domain', 'example.com')
            ->assertJsonPath('data.identity.site_title', 'Example')
            ->assertJsonPath('data.identity.website_type', 'e-commerce')
            ->assertJsonPath('data.identity.brand', 'Example Brand')
            ->assertJsonPath('data.writing_context.tone', 'professional');

        self::assertArrayNotHasKey('indexability', $response->json('data'));
        self::assertStringNotContainsString('indexability', (string) $response->getContent());
        self::assertNotEmpty($response->json('data.important_pages'));
    }

    public function test_content_resource_is_distribution_only(): void
    {
        $token = $this->mintToken(7);
        $response = $this->getJson('/api/v1/access/'.$token.'/content')
            ->assertOk()
            ->assertJsonPath('data.schema', SeoAccessContentComposer::SCHEMA)
            ->assertJsonPath('data.distribution.posts', 600)
            ->assertJsonPath('data.distribution.products', 514);

        $body = (string) $response->getContent();
        self::assertStringNotContainsString('"inventory"', $body);
        self::assertStringNotContainsString('"published"', $body);
        self::assertStringNotContainsString('"draft"', $body);
        self::assertStringNotContainsString('"scheduled"', $body);
        self::assertStringNotContainsString('"private"', $body);
        self::assertStringNotContainsString('"total"', $body);
    }

    public function test_keywords_get_landscape_and_post_relationship(): void
    {
        $token = $this->mintToken(7);

        $this->getJson('/api/v1/access/'.$token.'/keywords')
            ->assertOk()
            ->assertJsonPath('data.schema', SeoAccessKeywordsComposer::SCHEMA_LANDSCAPE)
            ->assertJsonPath('data.landscape.topic_count', 1);

        $ok = $this->postJson('/api/v1/access/'.$token.'/keywords', [
            'keyword_ref' => 'keyword:123',
            'sections' => ['keyword', 'topics', 'focus_articles', 'internal_links'],
        ])->assertOk();

        self::assertSame(SeoAccessKeywordsComposer::SCHEMA_RELATIONSHIP, $ok->json('data.schema'));
        self::assertArrayHasKey('keyword', $ok->json('data.relationship'));
        self::assertArrayHasKey('internal_links', $ok->json('data.relationship'));
        self::assertArrayNotHasKey('gsc', $ok->json('data.relationship'));
        self::assertArrayNotHasKey('router', $ok->json('data'));
        self::assertArrayNotHasKey('part', $ok->json('data'));

        $this->postJson('/api/v1/access/'.$token.'/keywords', [
            'keyword_ref' => 'keyword:1',
            'sections' => ['not_a_section'],
        ])->assertStatus(422);

        $this->postJson('/api/v1/access/'.$token.'/keywords', [
            'keyword_ref' => 'keyword:1',
            'router' => 'keywords',
        ])->assertStatus(422);
    }

    public function test_gsc_composed_response_and_memoization(): void
    {
        $counter = new class
        {
            public int $calls = 0;
        };
        $loader = new class($counter) implements GscContextLoader
        {
            public function __construct(private object $counter) {}

            public function forSite(int $siteId, string $periodKey): GscContext
            {
                $this->counter->calls++;

                return new GscContext(
                    siteId: $siteId,
                    periodKey: $periodKey,
                    metrics: [
                        'clicks' => 10,
                        'impressions' => 100,
                        'absent' => false,
                        'rising_count' => 1,
                        'falling_count' => 0,
                        'ctr_opportunity_count' => 0,
                        'near_page_one_count' => 0,
                        'content_decay_count' => 0,
                        'new_content_opportunity_count' => 0,
                        'possible_cannibalization_count' => 0,
                    ],
                    summary: [
                        'period' => ['current' => $periodKey],
                        'totals' => ['clicks' => 10],
                        'comparison' => [],
                        'top_queries' => [['query' => 'a']],
                        'top_pages' => [],
                        'rising_queries' => [],
                        'falling_queries' => [],
                        'high_impression_low_ctr' => [],
                        'near_page_one' => [],
                        'content_decay' => [],
                        'new_content_opportunities' => [],
                        'possible_cannibalization' => [],
                    ],
                    context: [],
                    sourceUpdatedAt: '2026-09-01T00:00:00+00:00',
                    generatedAt: '2026-09-26T00:00:00+00:00',
                    available: true,
                    stale: false,
                );
            }

            public function sourceUpdatedAt(int $siteId): ?string
            {
                return null;
            }
        };

        $source = new GscContextSource($loader);
        $this->app->instance(GscContextSource::class, $source);
        $this->app->forgetInstance(SeoAccessGscComposer::class);

        $token = $this->mintToken(7);
        $response = $this->getJson('/api/v1/access/'.$token.'/gsc?period=2026-08')
            ->assertOk()
            ->assertJsonPath('data.schema', SeoAccessGscComposer::SCHEMA)
            ->assertJsonPath('data.period', '2026-08');

        self::assertArrayHasKey('performance', $response->json('data'));
        self::assertArrayHasKey('opportunities', $response->json('data'));
        self::assertArrayHasKey('cannibalization', $response->json('data'));
        self::assertSame(1, $counter->calls);

        $this->getJson('/api/v1/access/'.$token.'/gsc?period=2026-08&include=performance')
            ->assertOk()
            ->assertJsonMissingPath('data.opportunities');
        self::assertSame(1, $counter->calls);
    }

    public function test_temporary_token_cannot_authorize_draft_intake(): void
    {
        $token = $this->mintToken(7);
        $this->postJson('/api/v1/services/seo/content-projects/draft/intake', [
            'site_id' => 7,
            'items' => [['keyword' => 'k', 'title' => 't', 'type' => 'new']],
        ], [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(401);

        $readOnly = app(ServiceApiCredentialManager::class)->create($this->seo, 'R', ['seo:read']);
        $this->postJson('/api/v1/services/seo/content-projects/draft/intake', [
            'site_id' => 7,
            'items' => [['keyword' => 'k', 'title' => 't', 'type' => 'new']],
        ], [
            'Authorization' => 'Bearer '.$readOnly->rawKey,
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'service_api_scope_denied');
    }

    public function test_expired_and_inactive_service_invalidate_token(): void
    {
        $manager = app(TemporaryServiceAccessManager::class);
        $issued = $manager->issue(
            $this->seo,
            \App\Models\ServiceApiCredential::query()->findOrFail($this->credentialId),
            7,
        );
        $payload = Cache::get($manager->cacheKey($issued->lookupId));
        self::assertIsArray($payload);
        $payload['expires_at'] = now()->subMinute()->toAtomString();
        Cache::put($manager->cacheKey($issued->lookupId), $payload, 900);

        $this->getJson('/api/v1/access/'.$issued->rawToken)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'service_api_temporary_access_invalid');

        $token = $this->mintToken(7);
        Service::query()->whereKey($this->seo->id)->update(['is_active' => false]);
        $this->getJson('/api/v1/access/'.$token)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'service_api_temporary_access_invalid');
    }

    private function mintToken(int $siteId): string
    {
        $response = $this->postJson('/api/v1/services/seo/access', [
            'site_id' => $siteId,
        ], $this->auth())->assertOk();

        return $this->tokenFromAccessUrl((string) $response->json('data.access_url'));
    }

    private function tokenFromAccessUrl(string $accessUrl): string
    {
        $path = parse_url($accessUrl, PHP_URL_PATH);
        self::assertIsString($path);
        $parts = explode('/', trim($path, '/'));
        $token = end($parts);
        self::assertIsString($token);
        self::assertStringStartsWith('access_tmp_', $token);

        return $token;
    }

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->readKey];
    }

    private function bindStubAccessStack(): void
    {
        $site = \Mockery::mock(SeoAccessSiteKnowledgeComposer::class);
        $site->shouldReceive('compose')->andReturnUsing(function (int $siteId): array {
            return [
                'schema' => SeoAccessSiteKnowledgeComposer::SCHEMA,
                'site_ref' => 'site:'.$siteId,
                'identity' => [
                    'domain' => 'example.com',
                    'site_title' => 'Example',
                    'website_type' => 'e-commerce',
                    'discovery_strategy' => null,
                    'brand' => 'Example Brand',
                    'company_short_identity' => 'Example Co',
                    'short_description' => 'Sells bags',
                    'cms' => 'wordpress',
                ],
                'writing_context' => [
                    'tone' => 'professional',
                    'business_summary' => 'Bag retailer',
                    'cta_instructions' => 'Call us',
                ],
                'contact' => ['phones' => [], 'emails' => [], 'socials' => [], 'address' => null],
                'important_pages' => [['keyword' => 'balo laptop', 'url' => '/product-category/balo/']],
                'available' => true,
                'generated_at' => now()->toIso8601String(),
            ];
        });
        $site->shouldReceive('listRow')->andReturnUsing(function (Site $site): array {
            return [
                'site_ref' => 'site:'.(int) $site->id,
                'domain' => (string) $site->domain,
                'title' => null,
            ];
        });
        $this->app->instance(SeoAccessSiteKnowledgeComposer::class, $site);

        $content = \Mockery::mock(SeoAccessContentComposer::class);
        $content->shouldReceive('compose')->andReturnUsing(function (int $siteId): array {
            return [
                'schema' => SeoAccessContentComposer::SCHEMA,
                'site_ref' => 'site:'.$siteId,
                'generated_at' => now()->toIso8601String(),
                'distribution' => [
                    'posts' => 600,
                    'pages' => 10,
                    'categories' => 6,
                    'products' => 514,
                    'product_categories' => 45,
                    'other' => 0,
                    'available' => true,
                ],
            ];
        });
        $this->app->instance(SeoAccessContentComposer::class, $content);

        $keywords = \Mockery::mock(SeoAccessKeywordsComposer::class);
        $keywords->shouldReceive('landscape')->andReturnUsing(function (int $siteId): array {
            return [
                'schema' => SeoAccessKeywordsComposer::SCHEMA_LANDSCAPE,
                'site_ref' => 'site:'.$siteId,
                'generated_at' => now()->toIso8601String(),
                'source_updated_at' => null,
                'landscape' => [
                    'topic_count' => 1,
                    'topics' => [
                        'items' => [['id' => 1, 'name' => 'Bags', 'coverage' => 'partial', 'article_count' => 2]],
                        'truncated' => false,
                        'returned' => 1,
                        'total' => 1,
                    ],
                ],
            ];
        });
        $keywords->shouldReceive('relationship')->andReturnUsing(function (int $siteId, array $input): array {
            $sections = $input['sections'] ?? null;
            $full = [
                'keyword' => ['id' => 123],
                'topics' => [['id' => 1]],
                'focus_articles' => [['article_id' => 3]],
                'related_keywords' => ['items' => []],
                'internal_links' => ['available' => true],
                'gsc' => ['available' => true],
                'meta' => [],
            ];
            $data = \Omnichannel\Addons\Seo\Services\Context\Support\KeywordRelationshipSectionFilter::apply(
                $full,
                is_array($sections) ? $sections : null,
            );

            return [
                'schema' => SeoAccessKeywordsComposer::SCHEMA_RELATIONSHIP,
                'site_ref' => 'site:'.$siteId,
                'keyword_ref' => (string) ($input['keyword_ref'] ?? 'keyword:0'),
                'available' => true,
                'generated_at' => now()->toIso8601String(),
                'relationship' => $data,
            ];
        });
        $this->app->instance(SeoAccessKeywordsComposer::class, $keywords);
    }

    private function bootSitesSchema(): void
    {
        Schema::dropIfExists('sites');
        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('domain');
            $table->string('status')->default('active');
            $table->boolean('ssl')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function makeService(string $name, string $slug, bool $active = true): Service
    {
        return Service::query()->create([
            'name' => $name,
            'slug' => $slug,
            'addon_namespace' => 'App\\\\Addons\\\\Test',
            'db_connection' => 'mysql',
            'is_active' => $active,
            'config' => [],
            'service_key' => 'provisioned-internal-key',
        ]);
    }
}
