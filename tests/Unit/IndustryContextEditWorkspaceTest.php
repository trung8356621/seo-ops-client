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
