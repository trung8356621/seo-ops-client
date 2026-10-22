<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\QuickSetupWizard;
use App\Models\ApiConnection;
use App\Models\User;
use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

final class AdminQuickSetupWizardTest extends TestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.core_connection', 'sqlite');
        Config::set('database.default', 'sqlite');

        $this->createTestSchema();

        $this->owner = User::query()->create([
            'name' => 'Owner Test',
            'email' => 'owner_qs_test_' . bin2hex(random_bytes(3)) . '@example.test',
            'password' => Hash::make('Secret123!'),
            'role' => User::ROLE_OWNER,
            'status' => 'normal',
            'is_system' => false,
        ]);
    }

    private function createTestSchema(): void
    {
        $conn = 'sqlite';

        foreach ([
            'users',
            'wp_options',
            'api_connections',
            'sites',
            'h1_installations',
            'h1_credentials',
            'services',
        ] as $t) {
            Schema::connection($conn)->dropIfExists($t);
        }

        Schema::connection($conn)->create('users', function (Blueprint $table): void {
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

        Schema::connection($conn)->create('wp_options', function (Blueprint $table): void {
            $table->id();
            $table->string('option_name')->unique();
            $table->longText('option_value')->nullable();
            $table->string('autoload', 20)->default('no');
            $table->timestamps();
        });

        Schema::connection($conn)->create('api_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('provider', 64);
            $table->string('name');
            $table->text('api_key')->nullable();
            $table->boolean('is_global')->default(false);
            $table->string('status', 32)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::connection($conn)->create('sites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('domain')->unique();
            $table->string('status')->default('inactive');
            $table->boolean('ssl')->default(true);
            $table->boolean('h1_is_anchor')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::connection($conn)->create('h1_installations', function (Blueprint $table): void {
            $table->id();
            $table->string('installation_id')->unique();
            $table->string('client_version')->nullable();
            $table->string('control_server_url')->nullable();
            $table->unsignedInteger('credential_version')->default(1);
            $table->unsignedInteger('entitlement_revision')->default(1);
            $table->json('verified_snapshot')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();
        });

        Schema::connection($conn)->create('h1_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('installation_id');
            $table->string('credential_id')->unique();
            $table->unsignedInteger('version')->default(1);
            $table->text('secret')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::connection($conn)->create('services', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::connection($conn)->create('prompt_result_routing_attempts', function (Blueprint $table): void {
            $table->id();
            $table->string('addon', 32)->nullable()->index();
            $table->string('module', 64)->nullable()->index();
            $table->string('action', 64)->nullable()->index();
            $table->boolean('attempted')->default(false);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->timestamps();
        });
    }

    public function test_quick_setup_route_requires_authentication(): void
    {
        $response = $this->get('/admin/quick-setup');

        $this->assertTrue(
            $response->isRedirect() || $response->status() === 403 || $response->status() === 302,
        );
    }

    public function test_quick_setup_page_accessible_for_owner(): void
    {
        $response = $this->actingAs($this->owner)->get('/admin/quick-setup');

        $response->assertStatus(200);
        $response->assertSee('Thiết lập nhanh SEO-OPS');
    }

    public function test_dashboard_welcome_card_rendered_when_uncompleted(): void
    {
        WpOption::deleteOption('quick_setup_completed_at');
        WpOption::clearRequestCache();

        $response = $this->actingAs($this->owner)->get('/admin');

        $response->assertStatus(200);
        $response->assertSee('Chào mừng đến SEO-OPS');
        $response->assertSee('Bắt đầu thiết lập');
    }

    public function test_step_navigation_and_validation(): void
    {
        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);

        // Step 1 initial
        $testComponent->assertSet('currentStep', 1);

        // Invalid token rejection
        $testComponent->set('enrollmentToken', 'invalid_no_prefix')
            ->call('activateEnrollment')
            ->assertSet('enrollmentSuccess', false);

        // Navigate to Step 2
        $testComponent->call('goToStep', 2)
            ->assertSet('currentStep', 2);

        // Navigate to Step 3
        $testComponent->call('goToStep', 3)
            ->assertSet('currentStep', 3);

        // Empty API key rejection
        $testComponent->set('aiApiKey', '')
            ->call('testAiConnection')
            ->assertSet('aiTestStatus', 'error');

        // Navigate to Step 4
        $testComponent->call('goToStep', 4)
            ->assertSet('currentStep', 4);

        // Navigate to Step 5
        $testComponent->call('goToStep', 5)
            ->assertSet('currentStep', 5);

        // Verify checklist items exist
        $checklist = $testComponent->get('checklist');
        $this->assertIsArray($checklist);
        $this->assertGreaterThanOrEqual(5, count($checklist));

        // Finish setup
        $testComponent->call('finishQuickSetup');
        $this->assertNotEmpty(WpOption::get('quick_setup_completed_at'));
    }

    public function test_dashboard_shows_review_link_after_completion(): void
    {
        WpOption::set('quick_setup_completed_at', now()->toIso8601String());
        WpOption::clearRequestCache();

        $response = $this->actingAs($this->owner)->get('/admin');

        $response->assertStatus(200);
        $response->assertDontSee('Chào mừng đến SEO-OPS');
        $response->assertSee('Xem lại thiết lập');
    }
}
