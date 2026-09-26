<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Api\Auth\ServiceApiCredentialManager;
use App\Api\Mcp\TemporaryMcpAccessManager;
use App\Models\Service;
use App\Models\Site;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

final class TemporaryMcpAccessHttpTest extends TestCase
{
    use UsesServiceApiCredentialSchema;

    private Service $seo;

    private string $mcpKey = '';

    private int $credentialId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootServiceApiCredentialSchema();
        $this->bootSitesSchema();
        $this->bindStubMcpStack();
        Cache::flush();

        $this->seo = $this->makeService('SEO', 'seo');
        $created = app(ServiceApiCredentialManager::class)->create(
            $this->seo,
            'MCP',
            ['mcp:read'],
        );
        $this->mcpKey = $created->rawKey;
        $this->credentialId = (int) $created->credential->id;

        Site::query()->forceCreate([
            'id' => 123,
            'user_id' => 1,
            'domain' => 'example.test',
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

    public function test_mint_auth_and_validation_matrix(): void
    {
        $this->postJson('/api/v1/services/seo/mcp/access', ['site_id' => 123])
            ->assertStatus(401);

        $noScope = app(ServiceApiCredentialManager::class)->create($this->seo, 'S', ['service:read']);
        $this->postJson('/api/v1/services/seo/mcp/access', ['site_id' => 123], [
            'Authorization' => 'Bearer '.$noScope->rawKey,
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'service_api_scope_denied');

        $seed = $this->makeService('Seeding', 'seeding');
        $seedKey = app(ServiceApiCredentialManager::class)->create($seed, 'Seed', ['mcp:read']);
        $this->postJson('/api/v1/services/seo/mcp/access', ['site_id' => 123], [
            'Authorization' => 'Bearer '.$seedKey->rawKey,
        ])->assertStatus(403);

        $this->postJson('/api/v1/services/seo/mcp/access', ['site_id' => 0], $this->auth())
            ->assertStatus(422);
        $this->postJson('/api/v1/services/seo/mcp/access', ['site_id' => -1], $this->auth())
            ->assertStatus(422);
        $this->postJson('/api/v1/services/seo/mcp/access', ['site_id' => 999999], $this->auth())
            ->assertStatus(422);
        $this->postJson('/api/v1/services/seo/mcp/access', [
            'site_id' => 123,
            'ttl' => 60,
        ], $this->auth())->assertStatus(422);
    }

    public function test_mint_returns_temporary_access_url_and_cache_payload(): void
    {
        $response = $this->postJson('/api/v1/services/seo/mcp/access', [
            'site_id' => 123,
        ], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.site_ref', 'site:123');

        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $accessUrl = (string) $response->json('data.access_url');
        self::assertStringContainsString('/api/v1/mcp/access/mcp_tmp_', $accessUrl);
        self::assertNotEmpty($response->json('data.expires_at'));
        self::assertStringNotContainsString($this->mcpKey, (string) $response->getContent());
        self::assertStringNotContainsString('svc_live_', (string) $response->getContent());

        $token = $this->tokenFromAccessUrl($accessUrl);
        $manager = app(TemporaryMcpAccessManager::class);
        $lookup = $manager->extractLookupId($token);
        self::assertNotNull($lookup);

        $payload = Cache::get($manager->cacheKey($lookup));
        self::assertIsArray($payload);
        self::assertSame((int) $this->seo->id, (int) $payload['service_id']);
        self::assertSame($this->credentialId, (int) $payload['credential_id']);
        self::assertSame(123, (int) $payload['site_id']);
        self::assertSame(['mcp:read'], $payload['scopes']);
        self::assertArrayHasKey('token_hash', $payload);
        self::assertStringNotContainsString($token, json_encode($payload) ?: '');
        self::assertStringNotContainsString($this->mcpKey, json_encode($payload) ?: '');

        $dbDump = json_encode(DB::table('service_api_credentials')->get()->all()) ?: '';
        self::assertStringNotContainsString($token, $dbDump);
        self::assertStringNotContainsString('mcp_tmp_', $dbDump);
    }

    public function test_temporary_root_router_markdown_and_failures(): void
    {
        $token = $this->mintToken(123);
        $root = '/api/v1/mcp/access/'.$token;

        $response = $this->getJson($root)
            ->assertOk()
            ->assertJsonPath('data.schema', McpManifestBuilder::SCHEMA)
            ->assertJsonPath('data.site_ref', 'site:123')
            ->assertJsonPath('data.self', $root);

        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $keywords = collect($response->json('data.routers'))->firstWhere('key', 'keywords');
        self::assertNotNull($keywords);
        self::assertSame($root.'/keywords', $keywords['href']);
        self::assertStringNotContainsString($this->mcpKey, (string) $response->getContent());
        self::assertStringNotContainsString('svc_live_', (string) $response->getContent());

        $this->getJson($root.'/keywords')
            ->assertOk()
            ->assertJsonPath('data.key', 'keywords')
            ->assertJsonPath('data.self', $root.'/keywords')
            ->assertJsonPath('data.read.method', 'POST')
            ->assertJsonPath('data.read.href', $root.'/keywords/read');

        $this->getJson($root.'/planning')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'service_api_not_found');

        $this->get($root.'?format=markdown')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
            ->assertSee('# SEO MCP', false)
            ->assertSee('Site: site:123', false);

        $this->getJson('/api/v1/mcp/access/mcp_tmp_deadbeef_notreal')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'service_api_temporary_access_invalid');
    }

    public function test_temporary_selective_read_uses_bound_site_and_rejects_site_id(): void
    {
        $token = $this->mintToken(123);
        $readUrl = '/api/v1/mcp/access/'.$token.'/keywords/read';

        $ok = $this->postJson($readUrl, [
            'parts' => [
                'relationship' => [
                    'view' => 'summary',
                    'parameters' => [
                        'keyword_ref' => 'keyword:123',
                        'sections' => ['keyword', 'topics', 'focus_articles'],
                    ],
                ],
            ],
        ])->assertOk();

        self::assertSame('site:123', $ok->json('data.scope.site_ref'));
        self::assertSame(McpRouterReader::SCHEMA, $ok->json('data.schema'));
        $data = $ok->json('data.parts.relationship.data');
        self::assertArrayHasKey('keyword', $data);
        self::assertArrayHasKey('topics', $data);
        self::assertArrayHasKey('focus_articles', $data);
        self::assertArrayNotHasKey('related_keywords', $data);

        $this->postJson($readUrl, [
            'site_id' => 456,
            'parts' => [
                'relationship' => [
                    'view' => 'summary',
                    'parameters' => ['keyword_ref' => 'keyword:1'],
                ],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'service_api_validation_failed');

        $this->postJson($readUrl, [
            'site_id' => 123,
            'parts' => [
                'landscape' => ['view' => 'summary'],
            ],
        ])->assertStatus(422);
    }

    public function test_temporary_gsc_multipart_memoization_intact(): void
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
        $this->app->forgetInstance(\Omnichannel\Addons\Seo\Http\Controllers\ServiceApi\SeoMcpHttpSupport::class);

        $token = $this->mintToken(123);
        $response = $this->postJson('/api/v1/mcp/access/'.$token.'/gsc/read', [
            'parts' => [
                'performance' => ['view' => 'summary', 'parameters' => ['period' => '2026-08']],
                'opportunities' => ['view' => 'summary', 'parameters' => ['period' => '2026-08']],
                'cannibalization' => ['view' => 'summary', 'parameters' => ['period' => '2026-08']],
            ],
        ])->assertOk();

        self::assertSame(['performance', 'opportunities', 'cannibalization'], array_keys($response->json('data.parts')));
        self::assertSame(1, $counter->calls);
        self::assertSame('site:123', $response->json('data.scope.site_ref'));
    }

    public function test_expired_token_fails_consistently(): void
    {
        $manager = app(TemporaryMcpAccessManager::class);
        $issued = $manager->issue($this->seo, \App\Models\ServiceApiCredential::query()->findOrFail($this->credentialId), 123);
        $lookup = $issued->lookupId;
        $payload = Cache::get($manager->cacheKey($lookup));
        self::assertIsArray($payload);
        $payload['expires_at'] = now()->subMinute()->toAtomString();
        Cache::put($manager->cacheKey($lookup), $payload, 900);

        $root = '/api/v1/mcp/access/'.$issued->rawToken;
        $this->getJson($root)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'service_api_temporary_access_invalid');
        $this->getJson($root.'/keywords')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'service_api_temporary_access_invalid');
        $this->postJson($root.'/keywords/read', [
            'parts' => ['landscape' => true],
        ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'service_api_temporary_access_invalid');
    }

    public function test_service_deactivation_invalidates_temporary_token(): void
    {
        $token = $this->mintToken(123);
        $root = '/api/v1/mcp/access/'.$token;
        $this->getJson($root)->assertOk();

        Service::query()->whereKey($this->seo->id)->update(['is_active' => false]);

        $this->getJson($root)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'service_api_temporary_access_invalid');
    }

    public function test_permanent_routes_still_work_alongside_temporary(): void
    {
        $this->getJson('/api/v1/services/seo/mcp', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.schema', McpManifestBuilder::SCHEMA)
            ->assertJsonMissingPath('data.site_ref');

        $this->postJson('/api/v1/services/seo/mcp/keywords/read', [
            'site_id' => 123,
            'parts' => [
                'relationship' => [
                    'view' => 'summary',
                    'parameters' => ['keyword_ref' => 'keyword:1'],
                ],
            ],
        ], $this->auth())->assertOk();
    }

    private function mintToken(int $siteId): string
    {
        $response = $this->postJson('/api/v1/services/seo/mcp/access', [
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
        self::assertStringStartsWith('mcp_tmp_', $token);

        return $token;
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
                ];
                if (array_key_exists('limit', $request->parameters)) {
                    $data['seen_limit'] = $request->limit();
                }

                return ContextSlice::make($this->key, $request->siteId, $data, null, true, null, false);
            }
        };
    }
}
