<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Resources\IndustryContextProfileResource\Pages\EditIndustryContextProfile;
use App\IndustryContext\IndustryContextProfileManager;
use App\IndustryContext\IndustryContextSchema;
use App\Models\IndustryContextProfile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class IndustryContextEditWorkspaceTest extends TestCase
{
    private IndustryContextProfileManager $manager;

    private IndustryContextProfile $core;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.core_connection', 'sqlite');
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

        $this->manager = app(IndustryContextProfileManager::class);
        $this->core = $this->manager->createInitial('bags', 'Bags', $this->coreContext(), now()->addMonths(6));

        $admin = (new \App\Models\User)->forceFill(['id' => 1, 'role' => \App\Models\User::ROLE_ADMIN]);
        $this->actingAs($admin);
    }

    public function test_empty_discovery_branch_paste_and_save_creates_revision(): void
    {
        $page = new EditIndustryContextProfile;
        $page->record = $this->core;
        $page->workspaceCoreId = (int) $this->core->getKey();
        $page->selectedType = IndustryContextProfile::TYPE_DISCOVERY;

        self::assertNull($page->branch());
        $template = $page->canonicalTemplate(IndustryContextProfile::TYPE_DISCOVERY);
        self::assertSame(['schema_version' => '1.0', 'items' => []], $template);

        $page->fillFormForBranch(null);

        $discoveryPayload = [
            'schema_version' => '1.0',
            'items' => [
                [
                    'id' => 'd1',
                    'topic' => 'Túi vải du lịch',
                    'keywords' => ['túi vải', 'túi du lịch'],
                    'attention_reason' => 'Xu hướng du lịch bền vững',
                    'bridge' => ['refs' => ['core-ref']],
                ],
            ],
        ];

        // Simulate form state with pasted JSON and 6_months expiry
        $page->form->fill([
            'context_json' => json_encode($discoveryPayload, JSON_THROW_ON_ERROR),
            'expiry_preset' => '6_months',
            'expires_at_custom' => null,
        ]);

        $page->saveManualRevision();

        $saved = IndustryContextProfile::query()->where('key', 'bags')->where('type', 'discovery')->first();
        self::assertNotNull($saved);
        self::assertFalse((bool) $saved->is_active);
        self::assertSame($discoveryPayload, $saved->context_json);
        self::assertNotNull($saved->expires_at);
        self::assertSame($saved->id, $page->branch()?->id);
    }

    public function test_empty_breakout_and_match_validate_proper_schema_type(): void
    {
        $page = new EditIndustryContextProfile;
        $page->record = $this->core;
        $page->workspaceCoreId = (int) $this->core->getKey();

        // 1. Breakout validation: rejects invalid payload
        $page->selectedType = IndustryContextProfile::TYPE_BREAKOUT;
        $page->fillFormForBranch(null);
        $page->form->fill([
            'context_json' => json_encode(['schema_version' => '1.0', 'items' => [['bad' => true]]], JSON_THROW_ON_ERROR),
            'expiry_preset' => '6_months',
        ]);
        $page->saveManualRevision();
        self::assertSame(0, IndustryContextProfile::query()->where('key', 'bags')->where('type', 'breakout')->count());

        // Breakout validation: accepts valid payload
        $breakoutPayload = [
            'schema_version' => '1.0',
            'items' => [
                [
                    'id' => 'b1',
                    'topic' => 'Phong cách tối giản',
                    'attention_angle' => 'Lối sống tối giản hiện đại',
                    'possible_bridges' => ['Balo tối giản liên hệ với túi xách'],
                    'keywords' => ['balo tối giản'],
                ],
            ],
        ];
        $page->form->fill([
            'context_json' => json_encode($breakoutPayload, JSON_THROW_ON_ERROR),
            'expiry_preset' => '6_months',
        ]);
        $page->saveManualRevision();
        self::assertSame(1, IndustryContextProfile::query()->where('key', 'bags')->where('type', 'breakout')->count());

        // 2. Match validation: rejects invalid payload
        $page->selectedType = IndustryContextProfile::TYPE_MATCH;
        $page->fillFormForBranch(null);
        $page->form->fill([
            'context_json' => json_encode(['schema_version' => '1.0', 'items' => []], JSON_THROW_ON_ERROR),
            'expiry_preset' => 'never',
        ]);
        $page->saveManualRevision();
        self::assertSame(0, IndustryContextProfile::query()->where('key', 'bags')->where('type', 'match')->count());

        // Match validation: accepts valid payload
        $entity = ['canonical' => 'balo', 'aliases' => ['ba lô']];
        $matchPayload = [
            'schema_version' => '1.0',
            'taxonomy' => array_fill_keys(['products', 'product_families', 'materials', 'services', 'audiences', 'use_cases', 'features', 'adjacent_products'], [$entity]),
            'topic_rules' => ['generic_cores' => [$entity], 'service_intent_terms' => [$entity]],
            'aliases' => [],
            'ambiguities' => [],
        ];
        $page->form->fill([
            'context_json' => json_encode($matchPayload, JSON_THROW_ON_ERROR),
            'expiry_preset' => 'never',
        ]);
        $page->saveManualRevision();
        $match = IndustryContextProfile::query()->where('key', 'bags')->where('type', 'match')->first();
        self::assertNotNull($match);
        self::assertNull($match->expires_at);
        self::assertSame($matchPayload, $match->context_json);
    }

    public function test_edit_existing_revision_creates_new_row_and_old_json_unchanged(): void
    {
        $payload1 = [
            'schema_version' => '1.0',
            'items' => [
                [
                    'id' => 'd1',
                    'topic' => 'Topic 1',
                    'keywords' => ['k1'],
                    'attention_reason' => 'Reason 1',
                    'bridge' => ['refs' => ['core-ref']],
                ],
            ],
        ];

        $oldRevision = $this->manager->createAuxiliaryRevision('bags', 'discovery', $payload1);

        $page = new EditIndustryContextProfile;
        $page->record = $oldRevision;
        $page->workspaceCoreId = (int) $this->core->getKey();
        $page->selectedType = IndustryContextProfile::TYPE_DISCOVERY;
        $page->fillFormForBranch($oldRevision);

        $payload2 = [
            'schema_version' => '1.0',
            'items' => [
                [
                    'id' => 'd2',
                    'topic' => 'Topic 2 Modified',
                    'keywords' => ['k2'],
                    'attention_reason' => 'Reason 2',
                    'bridge' => ['refs' => ['core-ref']],
                ],
            ],
        ];

        $page->form->fill([
            'context_json' => json_encode($payload2, JSON_THROW_ON_ERROR),
            'expiry_preset' => '3_months',
        ]);
        $page->saveManualRevision();

        // Must create a new row
        self::assertSame(2, IndustryContextProfile::query()->where('key', 'bags')->where('type', 'discovery')->count());

        // Old row must be untouched
        $refreshedOld = $oldRevision->fresh();
        self::assertSame($payload1, $refreshedOld->context_json);

        // New row has updated content
        $latest = IndustryContextProfile::query()->where('key', 'bags')->where('type', 'discovery')->latest('id')->first();
        self::assertNotSame($oldRevision->id, $latest->id);
        self::assertSame($payload2, $latest->context_json);
    }

    public function test_expiry_never_persists_on_existing_revision(): void
    {
        $revision = $this->manager->createAuxiliaryRevision(
            'bags',
            'discovery',
            $this->validDiscoveryPayload(),
            now()->addMonths(6),
        );

        $page = new EditIndustryContextProfile;
        $page->record = $revision;
        $page->workspaceCoreId = (int) $this->core->getKey();
        $page->selectedType = IndustryContextProfile::TYPE_DISCOVERY;

        self::assertNotNull($revision->fresh()->expires_at);

        $page->handleExpiryPresetUpdated('never');

        self::assertNull($revision->fresh()->expires_at);
    }

    public function test_expiry_3_months_persists_on_existing_revision(): void
    {
        $revision = $this->manager->createAuxiliaryRevision(
            'bags',
            'discovery',
            $this->validDiscoveryPayload(),
            null,
        );

        $page = new EditIndustryContextProfile;
        $page->record = $revision;
        $page->workspaceCoreId = (int) $this->core->getKey();
        $page->selectedType = IndustryContextProfile::TYPE_DISCOVERY;

        self::assertNull($revision->fresh()->expires_at);

        $before = CarbonImmutable::now()->addMonthsNoOverflow(3)->subMinutes(1);
        $page->handleExpiryPresetUpdated('3_months');
        $after = CarbonImmutable::now()->addMonthsNoOverflow(3)->addMinutes(1);

        $expiresAt = $revision->fresh()->expires_at;
        self::assertNotNull($expiresAt);
        self::assertTrue($expiresAt->greaterThan($before) && $expiresAt->lessThan($after));
    }

    public function test_custom_datetime_persists_on_existing_revision(): void
    {
        $revision = $this->manager->createAuxiliaryRevision(
            'bags',
            'discovery',
            $this->validDiscoveryPayload(),
            null,
        );

        $page = new EditIndustryContextProfile;
        $page->record = $revision;
        $page->workspaceCoreId = (int) $this->core->getKey();
        $page->selectedType = IndustryContextProfile::TYPE_DISCOVERY;
        $page->fillFormForBranch($revision);

        // Setting preset to custom without datetime does not persist yet
        $page->handleExpiryPresetUpdated('custom');
        self::assertNull($revision->fresh()->expires_at);

        // Setting custom datetime persists
        $customDate = '2028-06-15 12:00:00';
        $page->form->fill([
            'context_json' => json_encode($this->validDiscoveryPayload(), JSON_THROW_ON_ERROR),
            'expiry_preset' => 'custom',
            'expires_at_custom' => $customDate,
        ]);
        $page->handleExpiresAtCustomUpdated($customDate);

        self::assertSame('2028-06-15 12:00:00', $revision->fresh()->expires_at?->format('Y-m-d H:i:s'));
    }

    public function test_changing_discovery_expiry_does_not_mutate_core(): void
    {
        $coreExpiry = '2027-10-01 08:30:00';
        $this->core->forceFill(['expires_at' => $coreExpiry])->save();

        $discovery = $this->manager->createAuxiliaryRevision(
            'bags',
            'discovery',
            $this->validDiscoveryPayload(),
            now()->addMonths(6),
        );

        $page = new EditIndustryContextProfile;
        $page->record = $discovery;
        $page->workspaceCoreId = (int) $this->core->getKey();
        $page->selectedType = IndustryContextProfile::TYPE_DISCOVERY;

        $page->handleExpiryPresetUpdated('never');

        // Discovery expiry is null
        self::assertNull($discovery->fresh()->expires_at);

        // Core expiry is completely untouched
        self::assertSame($coreExpiry, $this->core->fresh()->expires_at?->format('Y-m-d H:i:s'));
    }

    public function test_empty_branch_expiry_change_does_not_create_empty_db_row(): void
    {
        $page = new EditIndustryContextProfile;
        $page->record = $this->core;
        $page->workspaceCoreId = (int) $this->core->getKey();
        $page->selectedType = IndustryContextProfile::TYPE_DISCOVERY;

        self::assertNull($page->branch());

        $page->handleExpiryPresetUpdated('12_months');
        $page->handleExpiresAtCustomUpdated('2029-01-01 00:00:00');

        self::assertSame(0, IndustryContextProfile::query()->where('key', 'bags')->where('type', 'discovery')->count());
    }

    public function test_missing_query_defaults_correctly(): void
    {
        $page = new EditIndustryContextProfile;
        $page->record = $this->core;
        $page->workspaceCoreId = (int) $this->core->getKey();

        // No query param on core record defaults to core
        request()->query->remove('type');
        self::assertSame('core', $page->resolveSelectedType(null));

        // When record is an auxiliary branch, missing query defaults to that branch's type
        $discovery = $this->manager->createAuxiliaryRevision(
            'bags',
            'discovery',
            $this->validDiscoveryPayload(),
        );
        $page->record = $discovery;
        self::assertSame('discovery', $page->resolveSelectedType(null));
    }

    public function test_valid_type_query_resolution(): void
    {
        $page = new EditIndustryContextProfile;
        $page->record = $this->core;
        $page->workspaceCoreId = (int) $this->core->getKey();

        self::assertSame('discovery', $page->resolveSelectedType('discovery'));
        self::assertSame('breakout', $page->resolveSelectedType('breakout'));
        self::assertSame('match', $page->resolveSelectedType('match'));
        self::assertSame('core', $page->resolveSelectedType('core'));
    }

    public function test_invalid_type_falls_back_safely(): void
    {
        $page = new EditIndustryContextProfile;
        $page->record = $this->core;
        $page->workspaceCoreId = (int) $this->core->getKey();

        self::assertSame('core', $page->resolveSelectedType('unknown_type'));
        self::assertSame('core', $page->resolveSelectedType(''));
        self::assertSame('core', $page->resolveSelectedType('admin'));
    }

    public function test_existing_branch_loads_correct_revision(): void
    {
        $discovery = $this->manager->createAuxiliaryRevision(
            'bags',
            'discovery',
            $this->validDiscoveryPayload(),
            now()->addMonths(6),
        );

        request()->query->set('type', 'discovery');

        $page = new EditIndustryContextProfile;
        $page->mount($this->core->id);

        self::assertSame('discovery', $page->selectedType);
        self::assertNotNull($page->branch());
        self::assertSame($discovery->id, $page->branch()?->id);
        self::assertSame($discovery->id, $page->record->id);
    }

    public function test_missing_branch_gets_clean_draft_state_without_carrying_core_json(): void
    {
        request()->query->set('type', 'discovery');

        $page = new EditIndustryContextProfile;
        $page->mount($this->core->id);

        self::assertSame('discovery', $page->selectedType);
        self::assertNull($page->branch());

        $formState = $page->form->getRawState();
        $rawJson = $formState['context_json'] ?? '';
        $decoded = json_decode((string) $rawJson, true);

        // Must be clean discovery template, NOT Core's JSON
        self::assertSame('1.0', $decoded['schema_version'] ?? null);
        self::assertSame([], $decoded['items'] ?? null);
        self::assertArrayNotHasKey('identity', $decoded);
        self::assertArrayNotHasKey('industry_taxonomy', $decoded);

        // Expiry default for discovery is 6_months
        self::assertSame('6_months', $formState['expiry_preset'] ?? null);

        // Match test for never default
        request()->query->set('type', 'match');
        $matchPage = new EditIndustryContextProfile;
        $matchPage->mount($this->core->id);

        self::assertSame('match', $matchPage->selectedType);
        self::assertNull($matchPage->branch());
        $matchState = $matchPage->form->getRawState();
        $matchDecoded = json_decode((string) ($matchState['context_json'] ?? ''), true);
        self::assertSame('1.0', $matchDecoded['schema_version'] ?? null);
        self::assertArrayHasKey('taxonomy', $matchDecoded);
        self::assertSame('never', $matchState['expiry_preset'] ?? null);
    }

    public function test_generated_tab_urls_contain_correct_query_and_stable_core_id(): void
    {
        $page = new EditIndustryContextProfile;
        $page->record = $this->core;
        $page->workspaceCoreId = (int) $this->core->getKey();
        $page->selectedType = IndustryContextProfile::TYPE_DISCOVERY;

        $coreUrl = $page->tabUrl('core');
        $discoveryUrl = $page->tabUrl('discovery');
        $breakoutUrl = $page->tabUrl('breakout');
        $matchUrl = $page->tabUrl('match');

        self::assertStringContainsString('/'.$this->core->id.'/edit?type=core', $coreUrl);
        self::assertStringContainsString('/'.$this->core->id.'/edit?type=discovery', $discoveryUrl);
        self::assertStringContainsString('/'.$this->core->id.'/edit?type=breakout', $breakoutUrl);
        self::assertStringContainsString('/'.$this->core->id.'/edit?type=match', $matchUrl);
    }

    public function test_redirects_preserve_selected_type(): void
    {
        $page = new EditIndustryContextProfile;
        $page->record = $this->core;
        $page->workspaceCoreId = (int) $this->core->getKey();

        $page->selectedType = 'discovery';
        $redirectUrl = (new \ReflectionMethod($page, 'getRedirectUrl'))->invoke($page);
        self::assertStringContainsString('/'.$this->core->id.'/edit?type=discovery', $redirectUrl);

        $page->selectedType = 'match';
        $redirectUrlMatch = (new \ReflectionMethod($page, 'getRedirectUrl'))->invoke($page);
        self::assertStringContainsString('/'.$this->core->id.'/edit?type=match', $redirectUrlMatch);
    }

    public function test_discovery_revisions_only_contains_discovery_revisions(): void
    {
        $d1 = $this->manager->createAuxiliaryRevision('bags', 'discovery', $this->validDiscoveryPayload());
        $d2 = $this->manager->createAuxiliaryRevision('bags', 'discovery', $this->validDiscoveryPayload());
        $this->manager->createAuxiliaryRevision('bags', 'breakout', $this->validBreakoutPayload());

        $page = new EditIndustryContextProfile;
        $page->record = $this->core;
        $page->workspaceCoreId = (int) $this->core->getKey();
        $page->selectedType = IndustryContextProfile::TYPE_DISCOVERY;

        $revisions = $page->revisions();
        self::assertCount(2, $revisions);
        foreach ($revisions as $rev) {
            self::assertSame('discovery', $rev->type);
            self::assertSame('bags', $rev->key);
        }
        self::assertSame([$d2->id, $d1->id], $revisions->pluck('id')->all());
    }

    public function test_breakout_revisions_only_contains_breakout_revisions(): void
    {
        $this->manager->createAuxiliaryRevision('bags', 'discovery', $this->validDiscoveryPayload());
        $b1 = $this->manager->createAuxiliaryRevision('bags', 'breakout', $this->validBreakoutPayload());

        $page = new EditIndustryContextProfile;
        $page->record = $this->core;
        $page->workspaceCoreId = (int) $this->core->getKey();
        $page->selectedType = IndustryContextProfile::TYPE_BREAKOUT;

        $revisions = $page->revisions();
        self::assertCount(1, $revisions);
        self::assertSame($b1->id, $revisions->first()->id);
        self::assertSame('breakout', $revisions->first()->type);
    }

    public function test_match_revisions_only_contains_match_revisions(): void
    {
        $this->manager->createAuxiliaryRevision('bags', 'discovery', $this->validDiscoveryPayload());
        $m1 = $this->manager->createAuxiliaryRevision('bags', 'match', $this->validMatchPayload());

        $page = new EditIndustryContextProfile;
        $page->record = $this->core;
        $page->workspaceCoreId = (int) $this->core->getKey();
        $page->selectedType = IndustryContextProfile::TYPE_MATCH;

        $revisions = $page->revisions();
        self::assertCount(1, $revisions);
        self::assertSame($m1->id, $revisions->first()->id);
        self::assertSame('match', $revisions->first()->type);
    }

    public function test_core_does_not_leak_into_auxiliary_pager(): void
    {
        $core2 = $this->manager->createRevision($this->core, $this->coreContext());
        $d1 = $this->manager->createAuxiliaryRevision('bags', 'discovery', $this->validDiscoveryPayload());

        $page = new EditIndustryContextProfile;
        $page->record = $this->core;
        $page->workspaceCoreId = (int) $this->core->getKey();
        $page->selectedType = IndustryContextProfile::TYPE_DISCOVERY;

        $revisions = $page->revisions();
        self::assertCount(1, $revisions);
        self::assertFalse($revisions->contains('id', $this->core->id));
        self::assertFalse($revisions->contains('id', $core2->id));

        $resolved = $page->resolveBranchRevision($revisions, $this->core->id);
        self::assertSame($d1->id, $resolved?->id);
        self::assertSame('discovery', $resolved?->type);
    }

    public function test_invalid_or_missing_rev_query_falls_back_to_branch_latest(): void
    {
        $d1 = $this->manager->createAuxiliaryRevision('bags', 'discovery', $this->validDiscoveryPayload());
        $d2 = $this->manager->createAuxiliaryRevision('bags', 'discovery', $this->validDiscoveryPayload());

        $page = new EditIndustryContextProfile;
        $page->record = $this->core;
        $page->workspaceCoreId = (int) $this->core->getKey();
        $page->selectedType = IndustryContextProfile::TYPE_DISCOVERY;

        $revisions = $page->revisions();

        self::assertSame($d2->id, $page->resolveBranchRevision($revisions, null)?->id);
        self::assertSame($d2->id, $page->resolveBranchRevision($revisions, '')?->id);
        self::assertSame($d2->id, $page->resolveBranchRevision($revisions, 999999)?->id);
        self::assertSame($d2->id, $page->resolveBranchRevision($revisions, 'invalid_rev')?->id);
        self::assertSame($d1->id, $page->resolveBranchRevision($revisions, $d1->id)?->id);
        self::assertSame($d1->id, $page->resolveBranchRevision($revisions, (string) $d1->id)?->id);
    }

    public function test_save_identical_json_does_not_create_new_revision(): void
    {
        $payload = $this->validDiscoveryPayload();
        $d1 = $this->manager->createAuxiliaryRevision('bags', 'discovery', $payload);

        $page = new EditIndustryContextProfile;
        $page->record = $d1;
        $page->workspaceCoreId = (int) $this->core->getKey();
        $page->selectedType = IndustryContextProfile::TYPE_DISCOVERY;
        $page->fillFormForBranch($d1);

        $page->form->fill([
            'context_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            'expiry_preset' => '6_months',
        ]);
        $page->saveManualRevision();

        self::assertSame(1, IndustryContextProfile::query()->where('key', 'bags')->where('type', 'discovery')->count());
    }

    public function test_save_whitespace_or_formatting_only_differences_does_not_create_new_revision(): void
    {
        $payload = $this->validDiscoveryPayload();
        $d1 = $this->manager->createAuxiliaryRevision('bags', 'discovery', $payload);

        $page = new EditIndustryContextProfile;
        $page->record = $d1;
        $page->workspaceCoreId = (int) $this->core->getKey();
        $page->selectedType = IndustryContextProfile::TYPE_DISCOVERY;
        $page->fillFormForBranch($d1);

        $compactJson = "   \n".json_encode($payload)." \n\n  ";
        $page->form->fill([
            'context_json' => $compactJson,
            'expiry_preset' => '6_months',
        ]);
        $page->saveManualRevision();

        self::assertSame(1, IndustryContextProfile::query()->where('key', 'bags')->where('type', 'discovery')->count());
    }

    public function test_save_changed_json_creates_new_revision_and_redirects(): void
    {
        $payload1 = $this->validDiscoveryPayload();
        $d1 = $this->manager->createAuxiliaryRevision('bags', 'discovery', $payload1);

        $page = new EditIndustryContextProfile;
        $page->record = $d1;
        $page->workspaceCoreId = (int) $this->core->getKey();
        $page->selectedType = IndustryContextProfile::TYPE_DISCOVERY;
        $page->fillFormForBranch($d1);

        $payload2 = $payload1;
        $payload2['items'][0]['topic'] = 'Túi thời trang mới';

        $page->form->fill([
            'context_json' => json_encode($payload2, JSON_THROW_ON_ERROR),
            'expiry_preset' => '6_months',
        ]);
        $page->saveManualRevision();

        self::assertSame(2, IndustryContextProfile::query()->where('key', 'bags')->where('type', 'discovery')->count());
        $latest = IndustryContextProfile::query()->where('key', 'bags')->where('type', 'discovery')->latest('id')->first();
        self::assertNotNull($latest);
        self::assertNotSame($d1->id, $latest->id);
        self::assertSame('Túi thời trang mới', $latest->context_json['items'][0]['topic']);
    }

    public function test_editing_older_revision_compares_against_latest_revision_not_viewed_revision(): void
    {
        $payload1 = $this->validDiscoveryPayload();
        $payload1['items'][0]['topic'] = 'V1 Original';
        $d1 = $this->manager->createAuxiliaryRevision('bags', 'discovery', $payload1);

        $payload2 = $this->validDiscoveryPayload();
        $payload2['items'][0]['topic'] = 'V2 Latest';
        $d2 = $this->manager->createAuxiliaryRevision('bags', 'discovery', $payload2);

        $page = new EditIndustryContextProfile;
        $page->record = $d1;
        $page->workspaceCoreId = (int) $this->core->getKey();
        $page->selectedType = IndustryContextProfile::TYPE_DISCOVERY;
        $page->fillFormForBranch($d1);

        // When saving V1 content (different from V2 latest), it creates V3
        $page->form->fill([
            'context_json' => json_encode($payload1, JSON_THROW_ON_ERROR),
            'expiry_preset' => '6_months',
        ]);
        $page->saveManualRevision();

        self::assertSame(3, IndustryContextProfile::query()->where('key', 'bags')->where('type', 'discovery')->count());
        $v3 = IndustryContextProfile::query()->where('key', 'bags')->where('type', 'discovery')->latest('id')->first();
        self::assertSame('V1 Original', $v3->context_json['items'][0]['topic']);

        // Saving V3 content again does NOT create V4 because it matches the latest in DB
        $page->form->fill([
            'context_json' => json_encode($payload1, JSON_THROW_ON_ERROR),
            'expiry_preset' => '6_months',
        ]);
        $page->saveManualRevision();

        self::assertSame(3, IndustryContextProfile::query()->where('key', 'bags')->where('type', 'discovery')->count());
    }

    public function test_max_3_rule_preserved_after_new_save(): void
    {
        $p1 = $this->validDiscoveryPayload();
        $p1['items'][0]['id'] = 'd1';
        $p2 = $this->validDiscoveryPayload();
        $p2['items'][0]['id'] = 'd2';
        $p3 = $this->validDiscoveryPayload();
        $p3['items'][0]['id'] = 'd3';

        $d1 = $this->manager->createAuxiliaryRevision('bags', 'discovery', $p1);
        $d2 = $this->manager->createAuxiliaryRevision('bags', 'discovery', $p2);
        $d3 = $this->manager->createAuxiliaryRevision('bags', 'discovery', $p3);

        self::assertSame(3, IndustryContextProfile::query()->where('key', 'bags')->where('type', 'discovery')->count());

        $page = new EditIndustryContextProfile;
        $page->record = $d3;
        $page->workspaceCoreId = (int) $this->core->getKey();
        $page->selectedType = IndustryContextProfile::TYPE_DISCOVERY;
        $page->fillFormForBranch($d3);

        $p4 = $this->validDiscoveryPayload();
        $p4['items'][0]['id'] = 'd4';
        $page->form->fill([
            'context_json' => json_encode($p4, JSON_THROW_ON_ERROR),
            'expiry_preset' => '6_months',
        ]);
        $page->saveManualRevision();

        self::assertSame(3, IndustryContextProfile::query()->where('key', 'bags')->where('type', 'discovery')->count());
        self::assertNull(IndustryContextProfile::query()->find($d1->id));
        $latest = IndustryContextProfile::query()->where('key', 'bags')->where('type', 'discovery')->latest('id')->first();
        self::assertNotNull($latest);
        self::assertSame('d4', $latest->context_json['items'][0]['id']);
    }

    /** @return array<string, mixed> */
    private function validDiscoveryPayload(): array
    {
        return [
            'schema_version' => '1.0',
            'items' => [
                [
                    'id' => 'd1',
                    'topic' => 'Topic 1',
                    'keywords' => ['keyword'],
                    'attention_reason' => 'Reason',
                    'bridge' => ['refs' => ['core-ref']],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function validBreakoutPayload(): array
    {
        return [
            'schema_version' => '1.0',
            'items' => [
                [
                    'id' => 'b1',
                    'topic' => 'Phong cách tối giản',
                    'attention_angle' => 'Lối sống tối giản hiện đại',
                    'possible_bridges' => ['Balo tối giản liên hệ với túi xách'],
                    'keywords' => ['balo tối giản'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function validMatchPayload(): array
    {
        $entity = ['canonical' => 'balo', 'aliases' => ['ba lô']];

        return [
            'schema_version' => '1.0',
            'taxonomy' => array_fill_keys(['products', 'product_families', 'materials', 'services', 'audiences', 'use_cases', 'features', 'adjacent_products'], [$entity]),
            'topic_rules' => ['generic_cores' => [$entity], 'service_intent_terms' => [$entity]],
            'aliases' => [],
            'ambiguities' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function coreContext(): array
    {
        $context = array_fill_keys(IndustryContextSchema::TOP_LEVEL_KEYS, []);
        $context['schema_version'] = '1.0';
        foreach (array_diff(IndustryContextSchema::TOP_LEVEL_KEYS, ['schema_version', 'audiences', 'demand_drivers']) as $key) {
            $context[$key] = ['fixture' => null];
        }

        return $context;
    }
}
