<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Filament\Pages\Dashboard;
use App\Models\ApiConnection;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Omnichannel\Addons\AiPrompt\Services\AiTokenUsageAnalyticsService;
use Tests\TestCase;

final class OperationalDashboardUsageOverviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->string('role')->default('owner');
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('api_connections')) {
            Schema::create('api_connections', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('name');
                $table->string('provider');
                $table->text('api_key')->nullable();
                $table->string('base_url')->nullable();
                $table->string('status')->default('active');
                $table->decimal('balance', 14, 4)->nullable();
                $table->string('currency', 10)->default('USD');
                $table->string('balance_status', 32)->default('unknown');
                $table->decimal('balance_warning_threshold', 14, 4)->default(5.0000);
                $table->timestamp('balance_checked_at')->nullable();
                $table->text('balance_error')->nullable();
                $table->timestamps();
            });
        }

        $seoConn = (new \Omnichannel\Addons\AiPrompt\Models\PromptResultRoutingAttempt())->getConnectionName() ?: 'omi_seo_ai';
        if (! Schema::connection($seoConn)->hasTable('prompt_result_routing_attempts')) {
            Schema::connection($seoConn)->create('prompt_result_routing_attempts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('prompt_result_id')->nullable();
                $table->string('addon', 32)->nullable()->index();
                $table->string('module', 64)->nullable()->index();
                $table->string('action', 64)->nullable()->index();
                $table->string('provider', 64)->nullable();
                $table->string('model', 128)->nullable();
                $table->string('status', 32)->default('pending');
                $table->boolean('attempted')->default(true);
                $table->unsignedInteger('input_tokens')->nullable();
                $table->unsignedInteger('output_tokens')->nullable();
                $table->unsignedInteger('total_tokens')->nullable();
                $table->unsignedSmallInteger('attempt_sequence')->default(1);
                $table->timestamps();
            });
        }

        ApiConnection::query()->delete();
        User::query()->delete();

        $user = User::create([
            'name' => 'Owner Admin',
            'email' => 'dash-owner@example.com',
            'password' => bcrypt('secret'),
            'role' => 'owner',
        ]);
        $this->actingAs($user);
    }

    public function test_dashboard_reuses_usage_aggregation_and_wallet(): void
    {
        ApiConnection::create([
            'name' => 'DeepSeek Dash',
            'provider' => 'deepseek',
            'api_key' => 'sk-dash',
            'balance' => 7.5,
            'balance_status' => 'normal',
            'status' => 'active',
        ]);

        $component = Livewire::test(Dashboard::class);
        /** @var Dashboard $page */
        $page = $component->instance();

        self::assertTrue($page->canViewUsageOverview());

        $cards = $page->walletCards();
        self::assertCount(1, $cards);
        self::assertSame(7.5, $cards[0]['balance']);

        $summary = $page->tokenSummary();
        $viaService = app(AiTokenUsageAnalyticsService::class)->getSummary('30d', 'all');
        self::assertSame($viaService['total_tokens'], $summary['total_tokens']);
        self::assertSame($viaService['seo']['tokens'], $summary['seo']['tokens']);
        self::assertSame($viaService['seeding']['tokens'], $summary['seeding']['tokens']);

        $component->assertSee('Ví nhà cung cấp');
        $component->assertSee('Tổng tokens');
        $component->assertSee('Cảnh báo');
        $component->assertSee('Xu hướng tiêu thụ token');
        $component->assertSeeHtml('id="dashboard-usage-detail"');
        self::assertStringNotContainsString('/admin/usage', $component->html());
    }

    public function test_dashboard_shows_empty_usage_state(): void
    {
        $component = Livewire::test(Dashboard::class);
        $summary = $component->instance()->tokenSummary();
        self::assertSame(0, $summary['total_tokens']);
        $component->assertSee('Chưa có lượt sử dụng AI nào trong khoảng thời gian đã chọn.');
    }

    public function test_unavailable_provider_balance_not_shown_as_zero(): void
    {
        ApiConnection::create([
            'name' => 'No Balance Yet',
            'provider' => 'deepseek',
            'api_key' => 'sk-none',
            'balance' => null,
            'balance_status' => 'unknown',
            'status' => 'active',
        ]);

        $component = Livewire::test(Dashboard::class);
        $cards = $component->instance()->walletCards();
        self::assertNull($cards[0]['balance']);
        $component->assertSee('Không có số dư');
        $component->assertDontSee('$0.00');
    }
}
