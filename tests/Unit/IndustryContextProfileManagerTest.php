<?php

declare(strict_types=1);

namespace Tests\Unit;

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
            $table->string('schema_version')->default('1.0');
            $table->json('context_json');
            $table->boolean('is_active')->default(false)->index();
            $table->timestamps();
        });
        $this->manager = app(IndustryContextProfileManager::class);
    }

    public function test_initial_is_active_and_duplicate_normal_create_is_rejected(): void
    {
        $initial = $this->manager->createInitial('bags', 'Bags', $this->context('Bags'));
        self::assertTrue($initial->is_active);
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
}
