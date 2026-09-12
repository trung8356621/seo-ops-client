<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Models\ApiConnection;
use App\Services\Ai\AiOperationalAlertsService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AiOperationalAlertsServiceTest extends TestCase
{
    private AiOperationalAlertsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('api_connections')) {
            Schema::create('api_connections', function (Blueprint $table): void {
                $table->id();
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

        $this->service = app(AiOperationalAlertsService::class);
    }

    public function test_low_balance_creates_operational_alert(): void
    {
        ApiConnection::create([
            'name' => 'DeepSeek Main',
            'provider' => 'deepseek',
            'balance' => 3.21,
            'currency' => 'USD',
            'balance_warning_threshold' => 5.0,
            'balance_status' => 'low_balance',
            'balance_checked_at' => now(),
            'status' => 'active',
        ]);

        $alerts = $this->service->getActiveAlerts();

        self::assertCount(1, $alerts);
        $alert = $alerts[0];
        self::assertSame('low_balance', $alert['type']);
        self::assertSame('warning', $alert['severity']);
        self::assertStringContainsString('DeepSeek Main', $alert['title']);
        self::assertStringContainsString('3.21', $alert['message']);
        self::assertStringContainsString('5.00', $alert['message']);
        self::assertSame('Kiểm tra ví', $alert['action_label']);
        self::assertStringContainsString('tab=usage', $alert['action_url']);
    }

    public function test_zero_or_negative_balance_is_critical_severity(): void
    {
        ApiConnection::create([
            'name' => 'OpenRouter Depleted',
            'provider' => 'openrouter',
            'balance' => 0.00,
            'currency' => 'USD',
            'balance_warning_threshold' => 5.0,
            'balance_status' => 'low_balance',
            'balance_checked_at' => now(),
            'status' => 'active',
        ]);

        $alerts = $this->service->getActiveAlerts();

        self::assertCount(1, $alerts);
        self::assertSame('critical', $alerts[0]['severity']);
    }

    public function test_alert_automatically_clears_when_balance_exceeds_threshold(): void
    {
        $conn = ApiConnection::create([
            'name' => 'DeepSeek Refilled',
            'provider' => 'deepseek',
            'balance' => 2.00,
            'balance_warning_threshold' => 5.0,
            'balance_status' => 'low_balance',
            'balance_checked_at' => now(),
            'status' => 'active',
        ]);

        self::assertSame(1, $this->service->countActiveAlerts());

        // Nạp tiền: balance lên 20.0, status chuyển normal
        $conn->update([
            'balance' => 20.00,
            'balance_status' => 'normal',
            'balance_checked_at' => now(),
        ]);

        // Cảnh báo tự động biến mất, không cần thao tác bấm mark-as-read
        self::assertSame(0, $this->service->countActiveAlerts());
        self::assertEmpty($this->service->getActiveAlerts());
    }

    public function test_check_failed_only_alerts_after_six_hours_stale(): void
    {
        // 1. Check failed mới 1 giờ trước: KHÔNG tạo alert
        $recentFailed = ApiConnection::create([
            'name' => 'DeepSeek Transient Fail',
            'provider' => 'deepseek',
            'balance' => 15.00,
            'balance_status' => 'check_failed',
            'balance_error' => 'Connection timeout',
            'balance_checked_at' => Carbon::now()->subHour(),
            'status' => 'active',
        ]);

        self::assertEmpty($this->service->getActiveAlerts());

        // 2. Check failed từ 7 giờ trước (>= 6 giờ): Tạo alert balance_stale
        $recentFailed->update([
            'balance_checked_at' => Carbon::now()->subHours(7),
        ]);

        $alerts = $this->service->getActiveAlerts();
        self::assertCount(1, $alerts);
        $alert = $alerts[0];

        self::assertSame('balance_stale', $alert['type']);
        self::assertSame('warning', $alert['severity']);
        self::assertStringContainsString('7 giờ', $alert['message']);
        // Không được biến lỗi kiểm tra thành cảnh báo "hết tiền"
        self::assertStringNotContainsString('hết tiền', $alert['message']);
        self::assertStringNotContainsString('số dư thấp', $alert['message']);
    }

    public function test_unsupported_providers_do_not_generate_alerts(): void
    {
        ApiConnection::create([
            'name' => 'Claude Anthropic',
            'provider' => 'anthropic',
            'balance' => null,
            'balance_status' => 'unsupported',
            'status' => 'active',
        ]);

        self::assertEmpty($this->service->getActiveAlerts());
    }

    public function test_sorting_order_critical_then_warning_then_newest(): void
    {
        // Warning alert: Low balance $3.00
        ApiConnection::create([
            'name' => 'DeepSeek Warning',
            'provider' => 'deepseek',
            'balance' => 3.00,
            'balance_status' => 'low_balance',
            'balance_checked_at' => Carbon::now()->subMinute(),
            'status' => 'active',
        ]);

        // Critical alert: Zero balance
        ApiConnection::create([
            'name' => 'OpenRouter Critical',
            'provider' => 'openrouter',
            'balance' => 0.00,
            'balance_status' => 'low_balance',
            'balance_checked_at' => Carbon::now()->subMinutes(5),
            'status' => 'active',
        ]);

        $alerts = $this->service->getActiveAlerts();
        self::assertCount(2, $alerts);

        // Critical phải xếp trước Warning dù xảy ra trước
        self::assertSame('critical', $alerts[0]['severity']);
        self::assertStringContainsString('OpenRouter Critical', $alerts[0]['title']);

        self::assertSame('warning', $alerts[1]['severity']);
        self::assertStringContainsString('DeepSeek Warning', $alerts[1]['title']);
    }
}
