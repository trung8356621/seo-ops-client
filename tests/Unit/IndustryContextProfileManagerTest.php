<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\IndustryContext\IndustryContextExpiry;
use App\IndustryContext\IndustryContextProfileManager;
use App\IndustryContext\IndustryContextSchema;
use App\Models\IndustryContextProfile;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

final class IndustryContextProfileManagerTest extends TestCase
{
    private IndustryContextProfileManager $manager;

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
    }

    public function test_initial_is_active_and_duplicate_normal_create_is_rejected(): void
    {
        $initial = $this->manager->createInitial('bags', 'Bags', $this->context('Bags'));
        self::assertTrue($initial->is_active);
        self::assertSame('core', $initial->type);
        self::assertNull($initial->source_core_id);
        self::assertNull($initial->source_core_hash);
        $this->expectException(InvalidArgumentException::class);
        $this->manager->createInitial('bags', 'Duplicate', $this->context('Duplicate'));
    }

    public function test_revisions_activate_atomically_and_prune_oldest_inactive_to_three(): void
    {
        $initial = $this->manager->createInitial('bags', 'Bags', $this->context('Initial'));
        $second = $this->manager->createRevision($initial, $this->context('Second'));
        $third = $this->manager->createRevision($initial, $this->context('Third'));
        $fourth = $this->manager->createRevision($initial, $this->context('Fourth'));

        self::assertFalse($fourth->is_active);
        self::assertSame(3, IndustryContextProfile::query()->where('key', 'bags')->count());
        self::assertTrue(IndustryContextProfile::query()->whereKey($initial->id)->exists(), 'Active row must not be pruned.');
        self::assertFalse(IndustryContextProfile::query()->whereKey($second->id)->exists(), 'Oldest inactive row should be pruned.');

        $this->manager->activate($third);
        self::assertSame(1, IndustryContextProfile::query()->where('key', 'bags')->where('is_active', true)->count());
        self::assertSame($third->id, $this->manager->active('bags')?->id);
    }

    public function test_logical_representative_is_active_or_latest_fallback(): void
    {
        $active = $this->manager->createInitial('bags', 'Bags', $this->context('Initial'));
        $this->manager->createRevision($active, $this->context('Alternative'));
        self::assertSame([$active->id], IndustryContextProfile::query()->logicalRepresentatives()->pluck('id')->all());

        IndustryContextProfile::query()->where('key', 'bags')->update(['is_active' => false]);
        $latest = IndustryContextProfile::query()->where('key', 'bags')->orderByDesc('id')->firstOrFail();
        self::assertSame([$latest->id], IndustryContextProfile::query()->logicalRepresentatives()->pluck('id')->all());
    }

    public function test_expiry_is_revision_metadata_and_does_not_deactivate_or_prune_active_row(): void
    {
        $expiredAt = now()->subDay();
        $initial = $this->manager->createInitial('bags', 'Bags', $this->context('Initial'), $expiredAt);
        self::assertTrue($initial->is_active);
        self::assertSame('expired', IndustryContextExpiry::status($initial->expires_at));

        $revisionExpiry = now()->addMonths(6);
        $revision = $this->manager->createRevision($initial, $this->context('Revision'), expiresAt: $revisionExpiry);
        self::assertNotNull($revision->expires_at);
        self::assertNotSame($initial->expires_at?->toDateTimeString(), $revision->expires_at?->toDateTimeString());

        $this->manager->prune('bags');
        self::assertTrue(IndustryContextProfile::query()->whereKey($initial->id)->where('is_active', true)->exists());
    }

    public function test_expiry_presets_default_to_six_months_and_support_never(): void
    {
        $now = now()->startOfSecond();
        self::assertSame($now->copy()->addMonthsNoOverflow(6)->toDateTimeString(), IndustryContextExpiry::resolve(now: $now)?->toDateTimeString());
        self::assertNull(IndustryContextExpiry::resolve('never', now: $now));
        self::assertSame('Không hết hạn', IndustryContextExpiry::label(null));
    }

    public function test_type_branches_have_independent_activation_and_history_limits(): void
    {
        $core = $this->manager->createInitial('bags', 'Bags', $this->context('Core 1'));
        foreach (range(2, 4) as $revision) {
            $this->manager->createRevision($core, $this->context("Core {$revision}"));
        }

        $discovery = [];
        $breakout = [];
        $match = [];
        foreach (range(1, 4) as $revision) {
            $discovery[] = $this->manager->createAuxiliaryRevision('bags', 'discovery', $this->auxiliary('discovery', $revision));
            $breakout[] = $this->manager->createAuxiliaryRevision('bags', 'breakout', $this->auxiliary('breakout', $revision));
            $match[] = $this->manager->createAuxiliaryRevision('bags', 'match', $this->auxiliary('match', $revision));
        }

        self::assertSame(3, IndustryContextProfile::query()->where('key', 'bags')->where('type', 'core')->count());
        self::assertSame(3, IndustryContextProfile::query()->where('key', 'bags')->where('type', 'discovery')->count());
        self::assertSame(3, IndustryContextProfile::query()->where('key', 'bags')->where('type', 'breakout')->count());
        self::assertSame(3, IndustryContextProfile::query()->where('key', 'bags')->where('type', 'match')->count());
        self::assertSame(12, IndustryContextProfile::query()->where('key', 'bags')->count());
        self::assertTrue($this->manager->active('bags', 'core')?->is($core));

        $this->manager->activate($match[3]);
        self::assertTrue($this->manager->active('bags', 'match')?->is($match[3]));
        self::assertTrue($this->manager->active('bags', 'core')?->is($core));

        $this->manager->activate($discovery[2]);
        self::assertTrue($this->manager->active('bags', 'discovery')?->is($discovery[2]));
        self::assertTrue($this->manager->active('bags', 'core')?->is($core));

        $this->manager->activate($discovery[3]);
        self::assertTrue($this->manager->active('bags', 'discovery')?->is($discovery[3]));
        self::assertFalse($discovery[2]->refresh()->is_active);

        $this->manager->activate($breakout[3]);
        self::assertTrue($this->manager->active('bags', 'breakout')?->is($breakout[3]));
        self::assertTrue($this->manager->active('bags', 'discovery')?->is($discovery[3]));
        self::assertTrue($this->manager->active('bags', 'core')?->is($core));
    }

    public function test_auxiliary_creation_requires_active_core_and_stamps_backend_provenance(): void
    {
        try {
            $this->manager->createAuxiliaryRevision('missing', 'discovery', $this->auxiliary('discovery'));
            self::fail('Creating auxiliary context without active Core should fail.');
        } catch (InvalidArgumentException) {
        }

        $core = $this->manager->createInitial('bags', 'Bags', $this->context('Core'));
        $auxiliary = $this->manager->createAuxiliaryRevision('bags', 'discovery', $this->auxiliary('discovery'));

        self::assertSame($core->id, $auxiliary->source_core_id);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $auxiliary->source_core_hash);
        self::assertSame('Bags', $auxiliary->name);
        self::assertFalse($auxiliary->is_active);
        self::assertFalse($this->manager->isStale($auxiliary));

        $this->expectException(InvalidArgumentException::class);
        $this->manager->createRevision($auxiliary, $this->context('Wrong branch'));
    }

    public function test_stale_detection_uses_core_content_hash_not_row_id(): void
    {
        $core = $this->manager->createInitial('bags', 'Bags', $this->context('Same'));
        $auxiliary = $this->manager->createAuxiliaryRevision('bags', 'breakout', $this->auxiliary('breakout'));

        $identical = $this->manager->createRevision($core, array_reverse($this->context('Same'), true));
        $this->manager->activate($identical);
        self::assertFalse($this->manager->isStale($auxiliary));

        $changed = $this->manager->createRevision($identical, $this->context('Changed'));
        $this->manager->activate($changed);
        self::assertTrue($this->manager->isStale($auxiliary));

        $changed->forceFill(['is_active' => false])->save();
        self::assertTrue($this->manager->isStale($auxiliary));
    }

    public function test_auxiliary_schema_is_type_specific_and_unknown_types_fail_closed(): void
    {
        $this->manager->createInitial('bags', 'Bags', $this->context('Core'));

        $this->expectException(\UnexpectedValueException::class);
        $this->manager->createAuxiliaryRevision('bags', 'discovery', $this->auxiliary('breakout'));
    }

    public function test_auxiliary_json_cannot_be_saved_as_core_and_unknown_type_fails_closed(): void
    {
        try {
            IndustryContextProfile::query()->create([
                'key' => 'bags', 'name' => 'Bags', 'type' => 'core', 'schema_version' => '1.0',
                'context_json' => $this->auxiliary('discovery'), 'is_active' => false,
            ]);
            self::fail('Auxiliary JSON must not validate as Core.');
        } catch (\UnexpectedValueException) {
        }

        $this->expectException(\UnexpectedValueException::class);
        IndustryContextProfile::query()->create([
            'key' => 'bags', 'name' => 'Bags', 'type' => 'unknown', 'schema_version' => '1.0',
            'context_json' => $this->context('Core'), 'is_active' => false,
        ]);
    }

    public function test_logical_representatives_ignore_active_auxiliary_rows(): void
    {
        $core = $this->manager->createInitial('bags', 'Bags', $this->context('Core'));
        $auxiliary = $this->manager->createAuxiliaryRevision('bags', 'discovery', $this->auxiliary('discovery'));
        $this->manager->activate($auxiliary);

        self::assertSame([$core->id], IndustryContextProfile::query()->logicalRepresentatives()->pluck('id')->all());
    }

    /** @return array<string, mixed> */
    private function context(string $name): array
    {
        $context = array_fill_keys(IndustryContextSchema::TOP_LEVEL_KEYS, []);
        $context['schema_version'] = '1.0';
        foreach (array_diff(IndustryContextSchema::TOP_LEVEL_KEYS, ['schema_version', 'audiences', 'demand_drivers']) as $key) {
            $context[$key] = ['fixture' => null];
        }
        $context['identity'] = ['context_name' => $name];

        return $context;
    }

    /** @return array<string, mixed> */
    private function auxiliary(string $type, int $revision = 1): array
    {
        if ($type === 'match') {
            $entity = ['canonical' => "entity {$revision}", 'aliases' => []];

            return ['schema_version' => '1.0', 'taxonomy' => array_fill_keys(['products', 'product_families', 'materials', 'services', 'audiences', 'use_cases', 'features', 'adjacent_products'], [$entity]), 'topic_rules' => ['generic_cores' => [$entity], 'service_intent_terms' => [$entity]], 'aliases' => [], 'ambiguities' => []];
        }

        $item = $type === 'discovery'
            ? ['id' => "d{$revision}", 'topic' => 'Topic', 'keywords' => ['keyword'], 'attention_reason' => 'Reason', 'bridge' => ['refs' => ['core']]]
            : ['id' => "b{$revision}", 'topic' => 'Topic', 'attention_angle' => 'Angle', 'possible_bridges' => ['Bridge'], 'keywords' => ['keyword']];

        return ['schema_version' => '1.0', 'items' => [$item]];
    }
}
