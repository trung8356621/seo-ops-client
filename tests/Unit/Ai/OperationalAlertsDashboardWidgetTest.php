<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Filament\Widgets\OperationalAlertsDashboardWidget;
use App\Models\ApiConnection;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

final class OperationalAlertsDashboardWidgetTest extends TestCase
{
    private User $admin;

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

        ApiConnection::query()->delete();
        User::query()->delete();

        $this->admin = User::create([
            'name' => 'Owner Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'role' => 'owner',
        ]);

        $this->actingAs($this->admin);
    }

    public function test_renders_compact_empty_state_when_no_active_alerts(): void
    {
        ApiConnection::create([
            'name' => 'Healthy DeepSeek',
            'provider' => 'deepseek',
            'balance' => 25.00,
            'balance_warning_threshold' => 5.00,
            'balance_status' => 'normal',
            'status' => 'active',
        ]);

        $component = Livewire::test(OperationalAlertsDashboardWidget::class);

        $component->assertSee('Cần chú ý')
            ->assertSee('Không có cảnh báo quan trọng')
            ->assertDontSee('Kiểm tra ví');
    }

    public function test_renders_active_alerts_with_action_link(): void
    {
        ApiConnection::create([
            'name' => 'Depleted DeepSeek',
            'provider' => 'deepseek',
            'balance' => 1.25,
            'currency' => 'USD',
            'balance_warning_threshold' => 5.00,
            'balance_status' => 'low_balance',
            'balance_checked_at' => now(),
            'status' => 'active',
        ]);

        $component = Livewire::test(OperationalAlertsDashboardWidget::class);

        $component->assertSee('Cần chú ý')
            ->assertSee('Số dư Depleted DeepSeek thấp')
            ->assertSee('1.25')
            ->assertSee('5.00')
            ->assertSee('Kiểm tra ví')
            ->assertDontSee('Không có cảnh báo quan trọng');
    }

    public function test_alert_automatically_disappears_from_widget_on_refill(): void
    {
        $conn = ApiConnection::create([
            'name' => 'Dynamic DeepSeek',
            'provider' => 'deepseek',
            'balance' => 1.00,
            'balance_warning_threshold' => 5.00,
            'balance_status' => 'low_balance',
            'balance_checked_at' => now(),
            'status' => 'active',
        ]);

        $component = Livewire::test(OperationalAlertsDashboardWidget::class);
        $component->assertSee('Số dư Dynamic DeepSeek thấp');

        // Nạp tiền
        $conn->update([
            'balance' => 30.00,
            'balance_status' => 'normal',
        ]);

        // Refresh component
        $component->call('$refresh');
        $component->assertSee('Không có cảnh báo quan trọng')
            ->assertDontSee('Số dư Dynamic DeepSeek thấp');
    }
}
