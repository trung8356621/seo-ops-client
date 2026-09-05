<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use App\Filament\Widgets\ServiceQuickShortcutsWidget;
use App\Models\Service;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;
use Omnichannel\Addons\Seeding\Support\SeedingServiceResolver;
use Tests\TestCase;

/**
 * Dashboard shortcuts — avoids RefreshDatabase (sqlite SEO prompt migration breakage).
 */
final class ServiceQuickShortcutsDashboardTest extends TestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('services')) {
            Schema::create('services', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('addon_namespace');
                $table->string('db_connection')->default('mysql');
                $table->boolean('is_active')->default(true);
                $table->json('config')->nullable();
                $table->text('service_key')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->string('role')->default(User::ROLE_OWNER);
                $table->string('status')->default(User::STATUS_NORMAL);
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->timestamps();
            });
        }

        $this->owner = User::query()->create([
            'name' => 'Owner Dashboard',
            'email' => 'owner-dash-'.uniqid('', true).'@example.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);

        Service::query()->updateOrCreate(
            ['slug' => 'seo-content-ai'],
            [
                'name' => 'SEO',
                'addon_namespace' => 'App\\Addons\\SeoContentAi\\SeoContentAiServiceProvider',
                'db_connection' => 'omi_seo_ai',
                'is_active' => true,
                'config' => [],
            ],
        );

        Service::query()->updateOrCreate(
            ['slug' => SeedingServiceResolver::SLUG],
            [
                'name' => 'Seeding',
                'addon_namespace' => \Omnichannel\Addons\Seeding\SeedingServiceProvider::class,
                'db_connection' => SeedingServiceConfig::CONNECTION,
                'is_active' => true,
                'config' => ['enabled' => true],
            ],
        );
    }

    public function test_admin_panel_registers_quick_shortcuts_widget(): void
    {
        $panel = Filament::getPanel('admin');
        self::assertContains(ServiceQuickShortcutsWidget::class, $panel->getWidgets());
    }

    public function test_widget_cards_use_canonical_urls(): void
    {
        $this->actingAs($this->owner);

        $widget = app(ServiceQuickShortcutsWidget::class);
        $cards = $widget->cards();
        $bySlug = collect($cards)->keyBy('slug');

        self::assertTrue($bySlug->has('seo'));
        self::assertTrue($bySlug->has('seeding'));
        self::assertStringEndsWith('/seo', (string) $bySlug['seo']['open_url']);
        self::assertStringEndsWith('/seeding', (string) $bySlug['seeding']['open_url']);
    }

    public function test_livewire_widget_renders_seo_and_seeding_shortcuts(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ServiceQuickShortcutsWidget::class)
            ->assertSee('Dịch vụ')
            ->assertSee('SEO')
            ->assertSee('Seeding')
            ->assertSee('/seo')
            ->assertSee('/seeding')
            ->assertSee('Mở SEO')
            ->assertSee('Mở Seeding');
    }
}
