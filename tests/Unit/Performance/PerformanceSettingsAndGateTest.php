<?php

declare(strict_types=1);

namespace Tests\Unit\Performance;

use App\Core\Performance\ActiveUserPresence;
use App\Core\Performance\BackgroundHeavyPolicy;
use App\Core\Performance\HeavyWorkloadGate;
use App\Core\Performance\HeavyWorkloadMiddleware;
use App\Core\Performance\OptionalBackgroundHeavy;
use App\Core\Performance\PerformancePreset;
use App\Core\Performance\PerformanceSettings;
use App\Models\User;
use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PerformanceSettingsAndGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('wp_options')) {
            Schema::create('wp_options', function (Blueprint $table): void {
                $table->id();
                $table->string('option_name')->unique();
                $table->longText('option_value')->nullable();
                $table->string('autoload', 20)->default('no');
                $table->timestamps();
            });
        }
        WpOption::clearRequestCache();
        Cache::flush();
        app(PerformanceSettings::class)->forgetMemo();
        app(ActiveUserPresence::class)->flush();
    }

    public function test_default_preset_is_mid_and_persists(): void
    {
        $settings = app(PerformanceSettings::class);
        self::assertSame(PerformancePreset::Mid, $settings->preset());
        self::assertSame(3, $settings->maxHeavyConcurrent());
        self::assertSame(3, $settings->backgroundUserThreshold());
        self::assertSame('adaptive', $settings->backgroundMode());

        $settings->savePreset(PerformancePreset::Low);
        $settings->forgetMemo();
        WpOption::clearRequestCache();

        $reloaded = app(PerformanceSettings::class);
        $reloaded->forgetMemo();
        self::assertSame(PerformancePreset::Low, $reloaded->preset());
        self::assertSame(1, $reloaded->maxHeavyConcurrent());

        $reloaded->savePreset(PerformancePreset::Mid);
    }

    public function test_low_limits_heavy_concurrency_to_one(): void
    {
        app(PerformanceSettings::class)->savePreset(PerformancePreset::Low);
        $gate = app(HeavyWorkloadGate::class);
        self::assertSame(1, $gate->maxSlots());

        $first = $gate->tryAcquire(60);
        self::assertNotNull($first);
        $second = $gate->tryAcquire(60);
        self::assertNull($second);
        $first->release();

        $again = $gate->tryAcquire(60);
        self::assertNotNull($again);
        $again->release();
    }

    public function test_mid_limits_heavy_concurrency_to_three(): void
    {
        app(PerformanceSettings::class)->savePreset(PerformancePreset::Mid);
        $gate = app(HeavyWorkloadGate::class);
        self::assertSame(3, $gate->maxSlots());

        $a = $gate->tryAcquire(60);
        $b = $gate->tryAcquire(60);
        $c = $gate->tryAcquire(60);
        $d = $gate->tryAcquire(60);
        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertNotNull($c);
        self::assertNull($d);
        $a->release();
        $b->release();
        $c->release();
    }

    public function test_saved_mid_preset_picks_up_new_limit_without_resave(): void
    {
        // Persist only the preset key (as production already does) — limit comes from enum at runtime.
        WpOption::set(PerformanceSettings::OPTION_KEY, [
            PerformanceSettings::KEY_PRESET => PerformancePreset::Mid->value,
        ], 'yes');
        app(PerformanceSettings::class)->forgetMemo();
        WpOption::clearRequestCache();

        self::assertSame(3, app(PerformanceSettings::class)->maxHeavyConcurrent());
        self::assertSame(3, app(HeavyWorkloadGate::class)->maxSlots());
    }

    public function test_high_configures_four_slots_without_load_test(): void
    {
        app(PerformanceSettings::class)->savePreset(PerformancePreset::High);
        self::assertSame(4, app(HeavyWorkloadGate::class)->maxSlots());
        self::assertSame(6, app(PerformanceSettings::class)->backgroundUserThreshold());
    }

    public function test_switching_from_high_to_low_counts_higher_in_flight_slots(): void
    {
        $settings = app(PerformanceSettings::class);
        $gate = app(HeavyWorkloadGate::class);

        $settings->savePreset(PerformancePreset::High);
        $h0 = $gate->tryAcquire(60);
        $h1 = $gate->tryAcquire(60);
        self::assertNotNull($h0);
        self::assertNotNull($h1);

        $settings->savePreset(PerformancePreset::Low);
        $settings->forgetMemo();

        // Release slot 0 while slot 1 is still running
        $h0->release();

        // Under Low (limit 1), slot 1 is still occupied -> new acquire must be rejected
        $newJob = $gate->tryAcquire(60);
        self::assertNull($newJob);

        // Once slot 1 releases, Low can acquire slot 0
        $h1->release();
        $allowed = $gate->tryAcquire(60);
        self::assertNotNull($allowed);
        $allowed->release();
    }

    public function test_two_tabs_same_user_count_as_one_active_user(): void
    {
        $presence = app(ActiveUserPresence::class);
        $presence->ping(42);
        $presence->ping(42);
        $presence->touch(42);
        self::assertSame(1, $presence->count());

        $presence->ping(99);
        self::assertSame(2, $presence->count());
    }

    public function test_mid_defers_optional_background_above_three_active_users(): void
    {
        app(PerformanceSettings::class)->savePreset(PerformancePreset::Mid);
        $presence = app(ActiveUserPresence::class);
        $presence->ping(1);
        $presence->ping(2);
        $presence->ping(3);
        $presence->ping(4);

        $policy = app(BackgroundHeavyPolicy::class);
        self::assertFalse($policy->allowsOptionalBackground());
        self::assertTrue($policy->allowsOptionalBackground(3));
        self::assertFalse($policy->allowsOptionalBackground(4));
    }

    public function test_low_always_defers_optional_background(): void
    {
        app(PerformanceSettings::class)->savePreset(PerformancePreset::Low);
        app(ActiveUserPresence::class)->flush();
        self::assertFalse(app(BackgroundHeavyPolicy::class)->allowsOptionalBackground(0));
    }

    public function test_middleware_releases_when_no_capacity_without_running_job(): void
    {
        app(PerformanceSettings::class)->savePreset(PerformancePreset::Low);
        $held = app(HeavyWorkloadGate::class)->tryAcquire(60);
        self::assertNotNull($held);

        $job = new class
        {
            public int $released = 0;

            public int $timeout = 60;

            public function release(int $delay = 0): void
            {
                $this->released = $delay;
            }
        };

        $ran = false;
        $middleware = new HeavyWorkloadMiddleware(
            app(HeavyWorkloadGate::class),
            app(BackgroundHeavyPolicy::class),
        );
        $middleware->handle($job, static function () use (&$ran) {
            $ran = true;

            return 'ok';
        });

        self::assertFalse($ran);
        self::assertSame(HeavyWorkloadMiddleware::RELEASE_SECONDS, $job->released);
        $held->release();
    }

    public function test_middleware_defers_optional_background_on_low(): void
    {
        app(PerformanceSettings::class)->savePreset(PerformancePreset::Low);
        $job = new class implements OptionalBackgroundHeavy
        {
            public int $released = 0;

            public function isOptionalBackgroundHeavy(): bool
            {
                return true;
            }

            public function release(int $delay = 0): void
            {
                $this->released = $delay;
            }
        };

        $ran = false;
        (new HeavyWorkloadMiddleware())->handle($job, static function () use (&$ran) {
            $ran = true;

            return 'ok';
        });

        self::assertFalse($ran);
        self::assertSame(HeavyWorkloadMiddleware::BACKGROUND_RELEASE_SECONDS, $job->released);
    }

    public function test_performance_settings_page_is_admin_only(): void
    {
        self::assertFalse(\App\Filament\Pages\SystemPerformanceSettings::canAccess());

        $admin = new User;
        $admin->role = User::ROLE_ADMIN;
        $this->actingAs($admin);
        self::assertTrue(\App\Filament\Pages\SystemPerformanceSettings::canAccess());
    }

    public function test_core_settings_registers_performance_section(): void
    {
        $src = (string) file_get_contents(base_path('app/Core/Settings/CoreSettingsBootstrap.php'));
        self::assertStringContainsString("id: 'performance'", $src);
        self::assertStringContainsString('SystemPerformanceSettings', $src);
    }
}
