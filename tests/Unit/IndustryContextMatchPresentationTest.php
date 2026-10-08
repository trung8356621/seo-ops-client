<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Resources\IndustryContextProfileResource\Pages\EditIndustryContextProfile;
use App\IndustryContext\ActiveIndustryMatchRuleProvider;
use App\IndustryContext\IndustryContextProfileManager;
use App\IndustryContext\IndustryContextSchema;
use App\Models\IndustryContextProfile;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class IndustryContextMatchPresentationTest extends TestCase
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
            $table->string('source_core_hash', 64)->nullable();
            $table->boolean('is_active')->default(false)->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        $this->manager = app(IndustryContextProfileManager::class);
        $this->core = $this->manager->createInitial('bags', 'Bags', $this->coreContext(), now()->addMonths(6));
        $admin = (new \App\Models\User)->forceFill(['id' => 1, 'role' => \App\Models\User::ROLE_ADMIN]);
        $this->actingAs($admin);
    }

    public function test_preview_uses_selected_revision_and_runtime_stays_on_active(): void
    {
        $active = $this->manager->createAuxiliaryRevision('bags', IndustryContextProfile::TYPE_MATCH, $this->matchPayload('balo active'));
        $this->manager->activate($active);
        $preview = $this->manager->createAuxiliaryRevision('bags', IndustryContextProfile::TYPE_MATCH, $this->matchPayload('balo preview', enabled: false));

        $page = $this->page($preview);

        self::assertSame($active->id, $page->activeMatchRevision()?->id);
        self::assertFalse($preview->is_active);
        $view = $page->matchResearchView();
        self::assertSame('balo preview', $view['groups']['products'][0]['canonical']);
        self::assertSame('products', $view['groups']['products'][0]['group_type']);
        self::assertSame(['ba lô'], $view['groups']['products'][0]['aliases']);
        self::assertFalse($view['groups']['products'][0]['enabled']);
        self::assertArrayNotHasKey('positive_max', $view['groups']['products'][0]);
        self::assertSame('gia cong', $view['secondary']['generic_cores'][0]['canonical']);
        self::assertArrayNotHasKey('generic_cores', $view['groups']);
        self::assertSame(['ô'], $view['secondary']['ambiguities'][0]['do_not_confuse_with']);

        $runtime = (new ActiveIndustryMatchRuleProvider($this->manager))->rulesForKey('bags');
        self::assertSame('balo active', $runtime['products'][0]['canonical']);
    }

    public function test_save_match_json_creates_inactive_revision_and_keeps_active(): void
    {
        $active = $this->manager->createAuxiliaryRevision('bags', IndustryContextProfile::TYPE_MATCH, $this->matchPayload('balo active'));
        $this->manager->activate($active);
        $page = $this->page($active);
        $page->fillFormForBranch($active);
        $payload = $this->matchPayload('balo saved');
        $page->form->fill([
            'context_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            'expiry_preset' => 'never',
        ]);

        $page->saveManualRevision();

        $active->refresh();
        self::assertTrue($active->is_active);
        $latest = IndustryContextProfile::query()->where('key', 'bags')->where('type', 'match')->latest('id')->first();
        self::assertNotNull($latest);
        self::assertNotSame($active->id, $latest->id);
        self::assertFalse($latest->is_active);
        self::assertSame('balo saved', $latest->context_json['taxonomy']['products'][0]['canonical']);
    }

    public function test_activate_revision_switches_runtime_without_editing_json(): void
    {
        $first = $this->manager->createAuxiliaryRevision('bags', IndustryContextProfile::TYPE_MATCH, $this->matchPayload('balo first'));
        $this->manager->activate($first);
        $second = $this->manager->createAuxiliaryRevision('bags', IndustryContextProfile::TYPE_MATCH, $this->matchPayload('balo second'));
        $page = $this->page($second);

        $page->activateRevision((int) $second->id);

        self::assertSame($second->id, $page->activeMatchRevision()?->id);
        self::assertSame('balo second', (new ActiveIndustryMatchRuleProvider($this->manager))->rulesForKey('bags')['products'][0]['canonical']);
    }

    public function test_dirty_editor_blocks_revision_switch(): void
    {
        $first = $this->manager->createAuxiliaryRevision('bags', IndustryContextProfile::TYPE_MATCH, $this->matchPayload('balo first'));
        $second = $this->manager->createAuxiliaryRevision('bags', IndustryContextProfile::TYPE_MATCH, $this->matchPayload('balo second'));
        $page = $this->page($first);
        $page->fillFormForBranch($first);
        $page->form->fill([
            'context_json' => json_encode($this->matchPayload('unsaved'), JSON_THROW_ON_ERROR),
            'expiry_preset' => 'never',
        ]);

        self::assertTrue($page->editorIsDirty());
        $page->selectRevision((int) $second->id);

        self::assertSame($first->id, $page->record->id);
    }

    public function test_other_context_types_do_not_use_match_projection(): void
    {
        $page = $this->page($this->core);
        $page->selectedType = IndustryContextProfile::TYPE_DISCOVERY;
        self::assertSame(['groups' => [], 'secondary' => []], $page->matchResearchView());

        $blade = file_get_contents(resource_path('views/filament/resources/industry-context-profile-resource/pages/edit-industry-context-profile.blade.php'));
        self::assertIsString($blade);
        self::assertStringContainsString('Advanced — Source JSON', $blade);
        self::assertStringNotContainsString('IndustryGroupSemanticMatcher', $blade);
        self::assertStringContainsString("selectedType === 'match'", $blade);
    }

    private function page(IndustryContextProfile $record): EditIndustryContextProfile
    {
        $page = new EditIndustryContextProfile;
        $page->record = $record;
        $page->workspaceCoreId = (int) $this->core->getKey();
        $page->selectedType = $record->type === IndustryContextProfile::TYPE_CORE
            ? IndustryContextProfile::TYPE_MATCH
            : (string) $record->type;

        return $page;
    }

    /** @return array<string, mixed> */
    private function matchPayload(string $canonical, bool $enabled = true): array
    {
        $entity = ['canonical' => $canonical, 'aliases' => ['ba lô'], 'match_mode' => 'phrase', 'enabled' => $enabled, 'positive_max' => 0.9];

        return [
            'schema_version' => '1.0',
            'taxonomy' => array_fill_keys(['products', 'product_families', 'materials', 'services', 'audiences', 'use_cases', 'features', 'adjacent_products'], [$entity]),
            'topic_rules' => [
                'generic_cores' => [['canonical' => 'gia cong', 'aliases' => []]],
                'service_intent_terms' => [['canonical' => 'dat hang', 'aliases' => []]],
            ],
            'aliases' => [['canonical' => 'backpack', 'aliases' => ['balo']]],
            'ambiguities' => [['term' => 'dù', 'match_mode' => 'token', 'do_not_confuse_with' => ['ô']]],
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
