<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Api\Auth\ServiceApiCredentialManager;
use App\Models\Service;
use App\Models\Site;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Seo\Services\Context\Providers\GscCannibalizationSliceProvider;
use Omnichannel\Addons\Seo\Services\Context\Providers\GscOpportunitiesSliceProvider;
use Omnichannel\Addons\Seo\Services\Context\Providers\GscPerformanceSliceProvider;
use Omnichannel\Addons\Seo\Services\Context\Registry\ContextRegistry;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSlice;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceDefinition;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceKey;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceRequest;
use Omnichannel\Addons\Seo\Services\Context\Slice\Contracts\ContextSliceProvider;
use Omnichannel\Addons\Seo\Services\Context\Support\KeywordRelationshipSectionFilter;
use Omnichannel\Addons\Seo\Services\GscContext\Dto\GscContext;
use Omnichannel\Addons\Seo\Services\GscContext\GscContextLoader;
use Omnichannel\Addons\Seo\Services\GscContext\GscContextSource;
use Omnichannel\Addons\Seo\Services\Mcp\Catalog\SeoMcpRouterCatalog;
use Omnichannel\Addons\Seo\Services\Mcp\Manifest\McpManifestBuilder;
use Omnichannel\Addons\Seo\Services\Mcp\Manifest\McpManifestMarkdownPresenter;
use Omnichannel\Addons\Seo\Services\Mcp\Router\McpRouterReader;
use Omnichannel\Addons\Seo\Services\Mcp\Router\McpRouterRegistry;
use Tests\TestCase;
use Tests\Unit\Api\UsesServiceApiCredentialSchema;

final class SeoMcpServiceApiHttpTest extends TestCase
{
    use UsesServiceApiCredentialSchema;

    private Service $seo;

    private string $mcpKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootServiceApiCredentialSchema();
        $this->bootSitesSchema();
        $this->bindStubMcpStack();

        $this->seo = $this->makeService('SEO', 'seo');
        $created = app(ServiceApiCredentialManager::class)->create(
            $this->seo,
            'MCP',
            ['mcp:read'],
        );
        $this->mcpKey = $created->rawKey;

        Site::query()->forceCreate([
            'id' => 123,
            'user_id' => 1,
            'domain' => 'example.test',
            'status' => 'active',
            'ssl' => true,
        ]);
    }

    public function test_auth_matrix_for_mcp_endpoints(): void
    {
        $this->getJson('/api/v1/services/seo/mcp')
            ->assertStatus(401);

        $this->getJson('/api/v1/services/seo/mcp', [
            'Authorization' => 'Bearer not-valid',
        ])->assertStatus(401);

        $revoked = app(ServiceApiCredentialManager::class)->create($this->seo, 'R', ['mcp:read']);
        app(ServiceApiCredentialManager::class)->revoke($revoked->credential);
        $this->getJson('/api/v1/services/seo/mcp', [
            'Authorization' => 'Bearer '.$revoked->rawKey,
        ])->assertStatus(403);

        $expired = app(ServiceApiCredentialManager::class)->create(
            $this->seo,
            'E',
            ['mcp:read'],
            Carbon::now()->subMinute(),
        );
        $this->getJson('/api/v1/services/seo/mcp', [
            'Authorization' => 'Bearer '.$expired->rawKey,
        ])->assertStatus(403);

        $noScope = app(ServiceApiCredentialManager::class)->create($this->seo, 'S', ['service:read']);
        $this->getJson('/api/v1/services/seo/mcp', [
            'Authorization' => 'Bearer '.$noScope->rawKey,
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'service_api_scope_denied');

        $wildcard = app(ServiceApiCredentialManager::class)->create($this->seo, 'W', ['*']);
        $this->getJson('/api/v1/services/seo/mcp', [
            'Authorization' => 'Bearer '.$wildcard->rawKey,
        ])->assertOk();

        $seed = $this->makeService('Seeding', 'seeding');
        $seedKey = app(ServiceApiCredentialManager::class)->create($seed, 'Seed MCP', ['mcp:read', '*']);
        $this->getJson('/api/v1/services/seo/mcp', [
            'Authorization' => 'Bearer '.$seedKey->rawKey,
        ])->assertStatus(403);

        $this->getJson('/api/v1/services/seeding/mcp', [
            'Authorization' => 'Bearer '.$seedKey->rawKey,
        ])->assertStatus(403);
    }

    public function test_inactive_seo_service_denied(): void
    {
        Service::query()->where('slug', 'seo')->update(['is_active' => false]);
        $this->getJson('/api/v1/services/seo/mcp', $this->auth())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'service_api_service_inactive');
    }

    public function test_root_manifest_discovery(): void
    {
        $response = $this->getJson('/api/v1/services/seo/mcp', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.schema', McpManifestBuilder::SCHEMA);

        $keys = array_column($response->json('data.routers'), 'key');
        self::assertSame(['site', 'content', 'seo', 'publishing', 'keywords', 'gsc'], $keys);

        $keywords = collect($response->json('data.routers'))->firstWhere('key', 'keywords');
        self::assertNotNull($keywords);
        self::assertSame(['landscape', 'relationship'], array_column($keywords['parts'], 'key'));
        self::assertArrayHasKey('when_to_use', $keywords['parts'][0]);
        self::assertArrayHasKey('size_hint', $keywords['parts'][0]);
        self::assertStringNotContainsString('SliceProvider', (string) $response->getContent());
    }

    public function test_markdown_format_and_invalid_format(): void
    {
        $this->get('/api/v1/services/seo/mcp?format=markdown', $this->auth())
            ->assertOk()
            ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
            ->assertSee('# SEO MCP', false)
            ->assertSee('## keywords', false);

        $this->getJson('/api/v1/services/seo/mcp?format=xml', $this->auth())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'service_api_validation_failed');
    }

    public function test_router_manifest_and_unknown_router(): void
    {
        $this->getJson('/api/v1/services/seo/mcp/keywords', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.key', 'keywords')
            ->assertJsonPath('data.parts.0.key', 'landscape')
            ->assertJsonPath('data.parts.1.key', 'relationship')
            ->assertJsonPath('data.parts.1.context_key', ContextSliceKey::KEYWORDS_RELATIONSHIP)
            ->assertJsonPath('data.parts.1.default_view', 'standard')
            ->assertJsonPath('data.parts.1.required_parameters.0', 'keyword_ref');

        $this->getJson('/api/v1/services/seo/mcp/planning', $this->auth())
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'service_api_not_found');
    }

    public function test_selective_reads_and_parameter_isolation(): void
    {
        $this->postJson('/api/v1/services/seo/mcp/site/read', [
            'site_id' => 123,
            'parts' => ['health' => ['view' => 'summary']],
        ], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.schema', McpRouterReader::SCHEMA)
            ->assertJsonPath('data.router', 'site')
            ->assertJsonPath('data.scope.site_ref', 'site:123')
            ->assertJsonPath('data.parts.health.key', ContextSliceKey::SITE_HEALTH)
            ->assertJsonMissingPath('data.parts.indexability');

        $two = $this->postJson('/api/v1/services/seo/mcp/site/read', [
            'site_id' => 123,
            'parts' => [
                'health' => true,
                'indexability' => ['view' => 'detail'],
            ],
        ], $this->auth())->assertOk();
        self::assertSame(['health', 'indexability'], array_keys($two->json('data.parts')));

        $kw = $this->postJson('/api/v1/services/seo/mcp/keywords/read', [
            'site_id' => 123,
            'parts' => [
                'landscape' => ['view' => 'summary', 'parameters' => ['limit' => 5]],
                'relationship' => [
                    'view' => 'standard',
                    'parameters' => ['keyword_ref' => 'keyword:9'],
                ],
            ],
        ], $this->auth())->assertOk();
        self::assertSame(5, $kw->json('data.parts.landscape.data.seen_limit'));
        self::assertSame('keyword:9', $kw->json('data.parts.relationship.data.seen_keyword_ref'));
        self::assertArrayNotHasKey('seen_keyword_ref', $kw->json('data.parts.landscape.data'));
    }

    public function test_relationship_sections_and_invalid_section(): void
    {
        $ok = $this->postJson('/api/v1/services/seo/mcp/keywords/read', [
            'site_id' => 123,
            'parts' => [
                'relationship' => [
                    'view' => 'standard',
                    'parameters' => [
                        'keyword_ref' => 'keyword:11',
                        'sections' => ['keyword', 'topics'],
                    ],
                ],
            ],
        ], $this->auth())->assertOk();

        $data = $ok->json('data.parts.relationship.data');
        self::assertArrayHasKey('keyword', $data);
        self::assertArrayHasKey('topics', $data);
        self::assertArrayNotHasKey('focus_articles', $data);
        self::assertArrayNotHasKey('related_keywords', $data);
        self::assertArrayNotHasKey('internal_links', $data);
        self::assertArrayNotHasKey('gsc', $data);

        $this->postJson('/api/v1/services/seo/mcp/keywords/read', [
            'site_id' => 123,
            'parts' => [
                'relationship' => [
                    'parameters' => [
                        'keyword_ref' => 'keyword:11',
                        'sections' => ['keyword', 'nope'],
                    ],
                ],
            ],
        ], $this->auth())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'service_api_validation_failed');
    }

    public function test_validation_view_param_site_and_unknown_fields(): void
    {
        $this->postJson('/api/v1/services/seo/mcp/site/read', [
            'site_id' => 123,
            'parts' => ['health' => ['view' => 'mega']],
        ], $this->auth())->assertStatus(422);

        $this->postJson('/api/v1/services/seo/mcp/gsc/read', [
            'site_id' => 123,
            'parts' => ['performance' => ['parameters' => ['weird' => 1]]],
        ], $this->auth())->assertStatus(422);

        $this->postJson('/api/v1/services/seo/mcp/keywords/read', [
            'site_id' => 123,
            'parts' => ['relationship' => ['view' => 'summary']],
        ], $this->auth())->assertStatus(422);

        $this->postJson('/api/v1/services/seo/mcp/site/read', [
            'site_id' => 999999,
            'parts' => ['health' => true],
        ], $this->auth())->assertStatus(422);

        $this->postJson('/api/v1/services/seo/mcp/site/read', [
            'site_id' => 123,
            'parts' => ['health' => true],
            'extra' => 1,
        ], $this->auth())->assertStatus(422);
    }

    public function test_formatter_safety_preserves_zero_and_false(): void
    {
        $response = $this->postJson('/api/v1/services/seo/mcp/site/read', [
            'site_id' => 123,
            'parts' => ['health' => ['view' => 'summary']],
        ], $this->auth())->assertOk();
        $encoded = (string) $response->getContent();
        self::assertStringNotContainsString('ai_lines', $encoded);
        self::assertStringNotContainsString('"raw"', $encoded);
        self::assertSame(0, $response->json('data.parts.health.data.count_zero'));
        self::assertFalse($response->json('data.parts.health.data.flag_false'));
    }

    public function test_gsc_multipart_shares_scoped_source_load(): void
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
                    metrics: ['clicks' => 1, 'impressions' => 2, 'absent' => false],
                    summary: [
                        'period' => ['current' => $periodKey],
                        'totals' => ['clicks' => 1],
                        'comparison' => [],
                        'top_queries' => [],
                        'top_pages' => [],
                        'rising_queries' => [],
                        'falling_queries' => [],
                        'ctr_opportunities' => [],
                        'near_page_one' => [],
                        'decay_candidates' => [],
                        'new_content_candidates' => [],
                        'cannibalization' => [],
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
        $providers = [];
        foreach (ContextSliceKey::all() as $key) {
            $providers[] = match ($key) {
                ContextSliceKey::GSC_PERFORMANCE => new GscPerformanceSliceProvider($source),
                ContextSliceKey::GSC_OPPORTUNITIES => new GscOpportunitiesSliceProvider($source),
                ContextSliceKey::GSC_CANNIBALIZATION => new GscCannibalizationSliceProvider($source),
                default => $this->stubProvider($key),
            };
        }
        $registry = SeoMcpRouterCatalog::build(new ContextRegistry($providers));
        $this->app->instance(McpRouterRegistry::class, $registry);
        $this->app->instance(McpRouterReader::class, new McpRouterReader($registry));
        $this->app->instance(McpManifestMarkdownPresenter::class, new McpManifestMarkdownPresenter);

        $response = $this->postJson('/api/v1/services/seo/mcp/gsc/read', [
            'site_id' => 123,
            'parts' => [
                'performance' => ['view' => 'summary', 'parameters' => ['period' => '2026-08']],
                'opportunities' => ['view' => 'summary', 'parameters' => ['period' => '2026-08']],
                'cannibalization' => ['view' => 'summary', 'parameters' => ['period' => '2026-08']],
            ],
        ], $this->auth())->assertOk();

        self::assertSame(['performance', 'opportunities', 'cannibalization'], array_keys($response->json('data.parts')));
        self::assertSame(1, $counter->calls);
    }

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->mcpKey];
    }

    private function bindStubMcpStack(): void
    {
        $registry = SeoMcpRouterCatalog::build($this->stubContextRegistry());
        $this->app->instance(McpRouterRegistry::class, $registry);
        $this->app->instance(McpRouterReader::class, new McpRouterReader($registry));
        $this->app->instance(McpManifestMarkdownPresenter::class, new McpManifestMarkdownPresenter);
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

    private function stubContextRegistry(): ContextRegistry
    {
        $providers = [];
        foreach (ContextSliceKey::all() as $key) {
            $providers[] = $this->stubProvider($key);
        }

        return new ContextRegistry($providers);
    }

    private function stubProvider(string $key): ContextSliceProvider
    {
        return new class($key) implements ContextSliceProvider
        {
            public function __construct(private readonly string $key) {}

            public function definition(): ContextSliceDefinition
            {
                $required = $this->key === ContextSliceKey::KEYWORDS_RELATIONSHIP ? ['keyword_ref'] : [];
                $optional = match (true) {
                    str_starts_with($this->key, 'gsc.') => ['period', 'period_key', 'limit'],
                    $this->key === ContextSliceKey::KEYWORDS_RELATIONSHIP => ['keyword_id', 'sections'],
                    in_array($this->key, [
                        ContextSliceKey::SEO_FINDINGS,
                        ContextSliceKey::SEO_INTERNAL_LINKS,
                        ContextSliceKey::KEYWORDS_LANDSCAPE,
                    ], true) => ['limit'],
                    default => [],
                };

                return new ContextSliceDefinition(
                    key: $this->key,
                    description: 'Stub '.$this->key,
                    scope: 'site',
                    views: ['summary', 'standard', 'detail'],
                    defaultView: $this->key === ContextSliceKey::KEYWORDS_RELATIONSHIP ? 'standard' : 'summary',
                    requiredParameters: $required,
                    optionalParameters: $optional,
                    periodAware: str_starts_with($this->key, 'gsc.'),
                );
            }

            public function provide(ContextSliceRequest $request): ContextSlice
            {
                if ($this->key === ContextSliceKey::KEYWORDS_RELATIONSHIP) {
                    $full = [
                        'keyword' => ['id' => 11],
                        'topics' => [['id' => 1]],
                        'focus_articles' => [['article_id' => 3]],
                        'related_keywords' => ['items' => [['id' => 2]]],
                        'internal_links' => ['available' => true],
                        'gsc' => ['available' => true],
                        'meta' => ['relation_issues' => []],
                    ];
                    $data = KeywordRelationshipSectionFilter::apply($full, $request->sections());
                    if (array_key_exists('keyword_ref', $request->parameters) || array_key_exists('keyword_id', $request->parameters)) {
                        $data['seen_keyword_ref'] = $request->keywordRef();
                    }

                    return ContextSlice::make($this->key, $request->siteId, $data, null, true, null, false);
                }

                $data = [
                    'ok' => true,
                    'view' => $request->view->value,
                    'count_zero' => 0,
                    'flag_false' => false,
                    'ai_lines' => ['should be stripped'],
                    'raw' => 'nope',
                ];
                if (array_key_exists('limit', $request->parameters)) {
                    $data['seen_limit'] = $request->limit();
                }
                if (array_key_exists('keyword_ref', $request->parameters) || array_key_exists('keyword_id', $request->parameters)) {
                    $data['seen_keyword_ref'] = $request->keywordRef();
                }

                return ContextSlice::make($this->key, $request->siteId, $data, null, true, null, false);
            }
        };
    }
}
