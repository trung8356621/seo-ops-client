<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\QuickSetupWizard;
use App\Models\User;
use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AdminQuickSetupAaPanelCronStepTest extends TestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.core_connection', 'sqlite');
        Config::set('database.default', 'sqlite');

        foreach (['users', 'wp_options'] as $table) {
            Schema::connection('sqlite')->dropIfExists($table);
        }

        Schema::connection('sqlite')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('staff');
            $table->string('status')->default('normal');
            $table->boolean('is_system')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('wp_options', function (Blueprint $table): void {
            $table->id();
            $table->string('option_name')->unique();
            $table->longText('option_value')->nullable();
            $table->string('autoload', 20)->default('no');
            $table->timestamps();
        });

        WpOption::clearRequestCache();

        $this->owner = User::query()->create([
            'name' => 'Owner Cron Step',
            'email' => 'owner_cron_step_'.bin2hex(random_bytes(3)).'@example.test',
            'password' => Hash::make('Secret123!'),
            'role' => User::ROLE_OWNER,
            'status' => 'normal',
            'is_system' => false,
        ]);
    }

    public function test_wizard_has_five_steps_cron_step_and_preserves_completed_marker(): void
    {
        $this->assertSame(5, QuickSetupWizard::MAX_STEP);

        WpOption::set('quick_setup_completed_at', '2026-01-01T00:00:00+00:00');
        $this->assertSame('2026-01-01T00:00:00+00:00', WpOption::get('quick_setup_completed_at'));

        $view = (string) file_get_contents(resource_path('views/filament/pages/quick-setup-wizard.blade.php'));
        $this->assertStringContainsString('Bước {{ $currentStep }}/{{ $maxStep }}', $view);
        $this->assertStringContainsString("currentStep === 4", $view);
        $this->assertStringContainsString("currentStep === 5", $view);
        $this->assertStringContainsString('downloadAaPanelCronJson', $view);
        $this->assertStringContainsString('Bước 5: Tổng kết', $view);
        $this->assertStringNotContainsString('BackgroundCronTasks', $view);
        $this->assertStringNotContainsString('php artisan schedule:run', $view);
        $this->assertStringNotContainsString('base_path()', $view);
        $this->assertFileDoesNotExist(app_path('Filament/Pages/BackgroundCronTasks.php'));
        $this->assertFileDoesNotExist(app_path('Services/AaPanelCron/AaPanelCronStatusProbe.php'));
        $this->assertFileDoesNotExist(app_path('Services/AaPanelCron/AaPanelCronRuntimeSettings.php'));

        $source = (string) file_get_contents(app_path('Filament/Pages/QuickSetupWizard.php'));
        $this->assertStringContainsString('function downloadAaPanelCronJson', $source);
        $this->assertStringContainsString('function loadStep5Data', $source);
        $this->assertStringNotContainsString("'key' => 'cron'", $source);
    }

    public function test_download_aapanel_cron_json_from_wizard_method(): void
    {
        $this->actingAs($this->owner);
        $page = app(QuickSetupWizard::class);
        $response = app()->call([$page, 'downloadAaPanelCronJson']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(
            'seo-ops-aapanel-cron.json',
            (string) $response->headers->get('content-disposition')
        );

        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertCount(5, $decoded);
        $this->assertSame('SEO-OPS Scheduler', $decoded[0]['name'] ?? null);
    }
}
