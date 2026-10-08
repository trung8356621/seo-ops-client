<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\IndustryContext\ActiveIndustryMatchRuleProvider;
use App\IndustryContext\IndustryContextProfileManager;
use App\IndustryContext\IndustryContextSchema;
use App\Models\IndustryContextProfile;
use App\Models\Site;
use App\Models\SiteMeta;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Services\IndustryGroup\IndustryGroupReadModel;
use Omnichannel\Addons\SearchFoundation\Services\MatchResearch\IndustryMatchResearchProjector;
use Omnichannel\Addons\SearchFoundation\Services\MatchResearch\MatchResearchRegistryService;
use Tests\TestCase;

final class ActiveIndustryMatchB2bBackpackTest extends TestCase
{
    private IndustryContextProfileManager $manager;

    private ActiveIndustryMatchRuleProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.core_connection', 'sqlite');
        Schema::connection('sqlite')->dropIfExists('site_meta');
        Schema::connection('sqlite')->dropIfExists('sites');
        Schema::connection('sqlite')->dropIfExists('industry_context_profiles');
        Schema::connection('sqlite')->create('industry_context_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->index();
            $table->string('name');
            $table->string('type')->default('core');
            $table->string('schema_version')->default('1.0');
            $table->json('context_json');
            $table->unsignedBigInteger('source_core_id')->nullable();
            $table->char('source_core_hash', 64)->nullable();
            $table->boolean('is_active')->default(false)->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
        Schema::connection('sqlite')->create('sites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('domain');
            $table->string('status')->nullable();
            $table->boolean('ssl')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::connection('sqlite')->create('site_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });
        $this->manager = app(IndustryContextProfileManager::class);
        $this->provider = app(ActiveIndustryMatchRuleProvider::class);
    }

    public function test_inactive_b2b_backpack_match_yields_no_groups_until_activated(): void
    {
        $this->manager->createInitial('b2b-backpack', 'May balo - túi xách', $this->coreContext());
        $match = $this->manager->createAuxiliaryRevision('b2b-backpack', IndustryContextProfile::TYPE_MATCH, $this->matchPayload());
        $site = Site::query()->create(['domain' => 'maytuicanvas.com', 'status' => 'active', 'ssl' => true]);
        SiteMeta::query()->create(['site_id' => $site->id, 'meta_key' => 'seo_industry_context_key', 'meta_value' => 'b2b-backpack']);

        self::assertNull($this->manager->active('b2b-backpack', IndustryContextProfile::TYPE_MATCH));
        self::assertSame('match_revision_inactive', $this->provider->statusForKey('b2b-backpack'));
        self::assertSame([], $this->provider->rulesForKey('b2b-backpack'));
        self::assertNull($this->provider->provenanceForKey('b2b-backpack'));
        self::assertSame([], $this->provider->rulesForSite((int) $site->id));
        self::assertSame([], $this->resources());
        self::assertSame([], $this->industryGroups((int) $site->id));

        $activated = $this->manager->activate($match->fresh());

        self::assertTrue($activated->is_active);
        self::assertSame(IndustryContextProfile::TYPE_MATCH, $activated->type);
        self::assertSame($activated->id, $this->manager->active('b2b-backpack', IndustryContextProfile::TYPE_MATCH)?->id);
        self::assertSame('active', $this->provider->statusForKey('b2b-backpack'));
        $rules = $this->provider->rulesForKey('b2b-backpack');
        self::assertSame('balo học sinh cấp 1', $rules['products'][0]['canonical'] ?? null);
        self::assertSame($rules['products'], $this->provider->rulesForSite((int) $site->id)['products'] ?? null);
        self::assertNotSame([], $this->resources());
        $groups = $this->industryGroups((int) $site->id);
        self::assertNotSame([], $groups);
        self::assertSame('b2b-backpack', $groups[0]->industryContextKey);
        self::assertFalse($groups[0]->stale);
    }

    public function test_missing_match_revision_is_distinct_from_inactive(): void
    {
        $this->manager->createInitial('b2b-backpack', 'May balo - túi xách', $this->coreContext());

        self::assertSame('no_match_revision', $this->provider->statusForKey('b2b-backpack'));
        self::assertSame([], $this->provider->rulesForKey('b2b-backpack'));
    }

    /** @return list<\Omnichannel\Addons\SearchFoundation\DTO\MatchResearch\MatchResearchResource> */
    private function resources(): array
    {
        return (new IndustryMatchResearchProjector($this->provider))->project('b2b-backpack', 'vi');
    }

    /** @return list<\Omnichannel\Addons\SearchFoundation\DTO\IndustryGroup\IndustryGroup> */
    private function industryGroups(int $siteId): array
    {
        $registry = new MatchResearchRegistryService(null, new IndustryMatchResearchProjector($this->provider), null, null);

        return (new IndustryGroupReadModel($registry))->list($siteId, 'b2b-backpack', 'vi');
    }

    /** @return array<string, mixed> */
    private function coreContext(): array
    {
        $context = array_fill_keys(IndustryContextSchema::TOP_LEVEL_KEYS, []);
        $context['schema_version'] = '1.0';
        foreach (array_diff(IndustryContextSchema::TOP_LEVEL_KEYS, ['schema_version', 'audiences', 'demand_drivers']) as $key) {
            $context[$key] = ['fixture' => null];
        }
        $context['identity'] = ['context_name' => 'May balo - túi xách'];

        return $context;
    }

    /** @return array<string, mixed> */
    private function matchPayload(): array
    {
        $entity = ['canonical' => 'balo học sinh cấp 1', 'aliases' => ['balo cap 1']];

        return [
            'schema_version' => '1.0',
            'taxonomy' => array_fill_keys(['products', 'product_families', 'materials', 'services', 'audiences', 'use_cases', 'features', 'adjacent_products'], [$entity]),
            'topic_rules' => ['generic_cores' => [$entity], 'service_intent_terms' => [$entity]],
            'aliases' => [],
            'ambiguities' => [],
        ];
    }
}
