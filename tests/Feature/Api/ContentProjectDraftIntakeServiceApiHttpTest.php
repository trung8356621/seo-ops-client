<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Api\Auth\ServiceApiCredentialManager;
use App\Models\Service;
use App\Models\Site;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ServiceApi\ServiceApiDraftIntakeItemResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ServiceApi\ServiceApiDraftIntakeResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ServiceApi\ServiceApiDraftIntakeService;
use Tests\TestCase;
use Tests\Unit\Api\UsesServiceApiCredentialSchema;

final class ContentProjectDraftIntakeServiceApiHttpTest extends TestCase
{
    use UsesServiceApiCredentialSchema;

    private Service $seo;

    private string $writeKey = '';

    /** @var list<array{payload: array<string, mixed>, idem: ?string}> */
    private array $intakeCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootServiceApiCredentialSchema();
        $this->bootSitesSchema();
        $this->intakeCalls = [];

        $this->seo = $this->makeService('SEO', 'seo');
        $created = app(ServiceApiCredentialManager::class)->create(
            $this->seo,
            'Draft Write',
            ['content-projects:draft:write'],
        );
        $this->writeKey = $created->rawKey;

        Site::query()->forceCreate([
            'id' => 123,
            'user_id' => 1,
            'domain' => 'example.test',
            'status' => 'active',
            'ssl' => true,
        ]);
    }

    public function test_auth_matrix(): void
    {
        $body = [
            'site_id' => 123,
            'items' => [
                ['keyword' => 'k', 'title' => 't', 'type' => 'new'],
            ],
        ];

        $this->postJson('/api/v1/services/seo/content-projects/draft/intake', $body)
            ->assertStatus(401);

        $this->postJson('/api/v1/services/seo/content-projects/draft/intake', $body, [
            'Authorization' => 'Bearer not-valid',
        ])->assertStatus(401);

        $seoOnly = app(ServiceApiCredentialManager::class)->create($this->seo, 'SEO', ['seo:read']);
        $this->postJson('/api/v1/services/seo/content-projects/draft/intake', $body, [
            'Authorization' => 'Bearer '.$seoOnly->rawKey,
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'service_api_scope_denied');

        $svcRead = app(ServiceApiCredentialManager::class)->create($this->seo, 'R', ['service:read']);
        $this->postJson('/api/v1/services/seo/content-projects/draft/intake', $body, [
            'Authorization' => 'Bearer '.$svcRead->rawKey,
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'service_api_scope_denied');

        $seed = $this->makeService('Seeding', 'seeding');
        $seedKey = app(ServiceApiCredentialManager::class)->create($seed, 'S', ['content-projects:draft:write', '*']);
        $this->postJson('/api/v1/services/seo/content-projects/draft/intake', $body, [
            'Authorization' => 'Bearer '.$seedKey->rawKey,
        ])->assertStatus(403);

        Service::query()->whereKey($this->seo->id)->update(['is_active' => false]);
        $this->postJson('/api/v1/services/seo/content-projects/draft/intake', $body, $this->auth())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'service_api_service_inactive');
    }

    public function test_wildcard_allowed_and_validation(): void
    {
        $this->bindValidatingFakeIntake();

        $wildcard = app(ServiceApiCredentialManager::class)->create($this->seo, 'W', ['*']);
        $this->postJson('/api/v1/services/seo/content-projects/draft/intake', [
            'site_id' => 123,
            'items' => [['keyword' => 'k', 'title' => 't', 'type' => 'new']],
        ], [
            'Authorization' => 'Bearer '.$wildcard->rawKey,
        ])
            ->assertCreated()
            ->assertJsonPath('data.site_ref', 'site:123')
            ->assertJsonPath('data.added', 1);

        $this->postJson('/api/v1/services/seo/content-projects/draft/intake', [
            'site_id' => 0,
            'items' => [['keyword' => 'k', 'type' => 'new']],
        ], $this->auth())->assertStatus(422);

        $this->postJson('/api/v1/services/seo/content-projects/draft/intake', [
            'site_id' => 999999,
            'items' => [['keyword' => 'k', 'type' => 'new']],
        ], $this->auth())->assertStatus(422);

        $this->postJson('/api/v1/services/seo/content-projects/draft/intake', [
            'site_id' => 123,
            'items' => [],
        ], $this->auth())->assertStatus(422);

        $this->postJson('/api/v1/services/seo/content-projects/draft/intake', [
            'site_id' => 123,
            'items' => [
                ['keyword' => 'k', 'type' => 'new', 'site_id' => 456],
            ],
        ], $this->auth())->assertStatus(422);

        $this->postJson('/api/v1/services/seo/content-projects/draft/intake', [
            'site_id' => 123,
            'items' => [
                ['keyword' => 'k', 'type' => 'improve'],
            ],
        ], $this->auth())->assertStatus(422);

        $this->postJson('/api/v1/services/seo/content-projects/draft/intake', [
            'site_id' => 123,
            'project_ref' => 'cpj_x',
            'items' => [['keyword' => 'k', 'type' => 'new']],
        ], $this->auth())->assertStatus(422);
    }

    public function test_successful_intake_wire_format_and_idempotency_header(): void
    {
        $this->bindValidatingFakeIntake();

        $response = $this->postJson('/api/v1/services/seo/content-projects/draft/intake', [
            'site_id' => 123,
            'items' => [
                [
                    'keyword' => 'test agent draft keyword',
                    'title' => 'Test Agent Draft Article',
                    'type' => 'new',
                    'source' => [
                        'type' => 'agent',
                        'ref' => 'postman:test:001',
                        'reason' => 'service_api_smoke_test',
                    ],
                ],
            ],
        ], array_merge($this->auth(), [
            'Idempotency-Key' => 'postman-draft-test-001',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.draft_ref', 'cpj_test')
            ->assertJsonPath('data.site_ref', 'site:123')
            ->assertJsonPath('data.submitted', 1)
            ->assertJsonPath('data.added', 1)
            ->assertJsonPath('data.items.0.status', 'added');

        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertCount(1, $this->intakeCalls);
        self::assertSame('postman-draft-test-001', $this->intakeCalls[0]['idem']);
    }

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->writeKey];
    }

    private function bindValidatingFakeIntake(): void
    {
        $test = $this;
        $this->app->instance(ServiceApiDraftIntakeService::class, new class($test) extends ServiceApiDraftIntakeService
        {
            public function __construct(private readonly ContentProjectDraftIntakeServiceApiHttpTest $testCase)
            {
                // Skip parent DI — intake() fully overridden.
            }

            public function intake(array $payload, ?string $idempotencyKey = null): ServiceApiDraftIntakeResult
            {
                $this->testCase->recordIntakeCall($payload, $idempotencyKey);

                $siteId = (int) ($payload['site_id'] ?? 0);
                if ($siteId <= 0) {
                    throw new InvalidArgumentException('site_id is required and must be a positive integer.');
                }
                if ($siteId === 999999) {
                    throw new InvalidArgumentException('Unknown or invalid site_id.');
                }
                $items = $payload['items'] ?? null;
                if (! is_array($items) || $items === []) {
                    throw new InvalidArgumentException('items must be a non-empty array.');
                }
                $unknown = array_diff(array_keys($payload), ['site_id', 'items']);
                if ($unknown !== []) {
                    throw new InvalidArgumentException(
                        'Unknown request fields: '.implode(', ', array_values($unknown))
                    );
                }
                foreach ($items as $i => $item) {
                    if (! is_array($item)) {
                        throw new InvalidArgumentException('Each item must be an object.');
                    }
                    if (array_key_exists('site_id', $item)) {
                        throw new InvalidArgumentException('Item-level site_id is not allowed (index '.$i.').');
                    }
                    $type = trim((string) ($item['type'] ?? 'new'));
                    if (! in_array($type, ['new', 'rewrite'], true)) {
                        throw new InvalidArgumentException(
                            'Unsupported item type at index '.$i.'. Allowed: new, rewrite.'
                        );
                    }
                }

                return new ServiceApiDraftIntakeResult(
                    draftRef: 'cpj_test',
                    siteRef: 'site:'.$siteId,
                    submitted: count($items),
                    added: 1,
                    alreadyInDraft: 0,
                    failed: 0,
                    items: [
                        new ServiceApiDraftIntakeItemResult(0, 'added', 'cpi_test'),
                    ],
                );
            }
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordIntakeCall(array $payload, ?string $idempotencyKey): void
    {
        $this->intakeCalls[] = ['payload' => $payload, 'idem' => $idempotencyKey];
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
