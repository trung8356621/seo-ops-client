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
            $table->string('product', 64)->default('seo-ops');
            $table->string('client_version')->nullable();
            $table->string('control_server_url')->nullable();
            $table->string('status', 32)->default('enrolled');
            $table->string('cutover_mode', 32)->default('pre_enrollment');
            $table->string('anchor_identity')->nullable();
            $table->unsignedBigInteger('anchor_site_id')->nullable();
            $table->unsignedInteger('credential_version')->default(1);
            $table->unsignedInteger('entitlement_revision')->default(1);
            $table->string('last_command_id')->nullable();
            $table->json('verified_snapshot')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->json('pending_acks')->nullable();
            $table->json('rotation_state')->nullable();
            $table->timestamps();
        });

        Schema::connection($conn)->create('h1_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('installation_id');
            $table->string('credential_id')->unique();
            $table->unsignedInteger('version')->default(1);
            $table->text('secret_encrypted')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('retired_at')->nullable();
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

    public function test_dashboard_shows_unlicensed_warning_if_not_entitled(): void
    {
        WpOption::set('quick_setup_completed_at', now()->toIso8601String());
        WpOption::clearRequestCache();

        $response = $this->actingAs($this->owner)->get('/admin');

        $response->assertStatus(200);
        $response->assertDontSee('Chào mừng đến SEO-OPS');
        $response->assertSee('Chưa kích hoạt bản quyền thương mại');
    }

    public function test_dashboard_shows_review_link_after_licensed_and_configured(): void
    {
        WpOption::set('quick_setup_completed_at', now()->toIso8601String());
        WpOption::clearRequestCache();

        config(['h1-license.enforcement_mode' => 'enforcing']);
        \App\Models\H1Installation::create([
            'installation_id' => 'inst_test',
            'product' => 'seo-ops',
            'control_server_url' => 'https://ops.example.com',
            'status' => 'active',
            'cutover_mode' => 'enforcing',
            'verified_snapshot' => [
                'services' => [
                    'seo' => ['enabled' => true],
                ],
            ],
        ]);

        \App\Models\ApiConnection::create([
            'name' => 'Test AI',
            'provider' => 'openai',
            'api_key' => 'sk-test',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->owner)->get('/admin');

        $response->assertStatus(200);
        $response->assertDontSee('Chào mừng đến SEO-OPS');
        $response->assertSee('Xem lại thiết lập');
    }

    public function test_quick_setup_prefills_existing_server_url(): void
    {
        WpOption::set('h1_control_server_url', 'http://localhost:3000');
        WpOption::clearRequestCache();

        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $testComponent->assertSet('serverUrl', 'http://localhost:3000');
    }

    public function test_step2_continue_checked_runs_bootstrap_and_advances_on_success(): void
    {
        $fake = $this->bindFakeGoldenBootstrap(static fn (): array => [
            'status' => 'bootstrapped',
            'imported' => true,
        ]);

        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $testComponent->assertSet('overwriteGoldenConfiguration', true);
        $testComponent->set('currentStep', 2)
            ->set('isSeoLicensed', true)
            ->set('goldenStatus', 'blank')
            ->set('overwriteGoldenConfiguration', true)
            ->call('nextStep')
            ->assertSet('currentStep', 3)
            ->assertSet('goldenSuccess', true)
            ->assertSet('goldenError', null);

        $this->assertCount(1, $fake->calls);
        $this->assertTrue($fake->calls[0]['overwrite']);
    }

    public function test_step2_continue_unchecked_skips_bootstrap_and_advances(): void
    {
        $fake = $this->bindFakeGoldenBootstrap(static fn (): array => [
            'status' => 'bootstrapped',
            'imported' => true,
        ]);

        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $testComponent->set('currentStep', 2)
            ->set('isSeoLicensed', true)
            ->set('goldenStatus', 'customized')
            ->set('overwriteGoldenConfiguration', false)
            ->call('nextStep')
            ->assertSet('currentStep', 3)
            ->assertSet('goldenError', null);

        $this->assertCount(0, $fake->calls);
    }

    public function test_step2_continue_checked_stays_on_step_on_bootstrap_failure(): void
    {
        $fake = $this->bindFakeGoldenBootstrap(static fn (): array => [
            'status' => 'download_failed',
            'imported' => false,
            'error_code' => 'server_offline',
        ]);

        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $testComponent->set('currentStep', 2)
            ->set('isSeoLicensed', true)
            ->set('goldenStatus', 'blank')
            ->set('overwriteGoldenConfiguration', true)
            ->call('nextStep')
            ->assertSet('currentStep', 2)
            ->assertSet('goldenSuccess', false);

        $error = (string) $testComponent->get('goldenError');
        $this->assertStringContainsString('server_offline', $error);
        $this->assertCount(1, $fake->calls);
    }

    public function test_step2_continue_duplicate_submission_is_ignored_while_applying(): void
    {
        $fake = $this->bindFakeGoldenBootstrap(static fn (): array => [
            'status' => 'bootstrapped',
            'imported' => true,
        ]);

        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $testComponent->set('currentStep', 2)
            ->set('isSeoLicensed', true)
            ->set('goldenStatus', 'blank')
            ->set('overwriteGoldenConfiguration', true)
            ->set('goldenApplying', true)
            ->call('nextStep')
            ->assertSet('currentStep', 2);

        $this->assertCount(0, $fake->calls);
    }

    public function test_step2_view_has_no_separate_apply_button_and_wires_continue_to_next_step(): void
    {
        $view = (string) file_get_contents(resource_path('views/filament/pages/quick-setup-wizard.blade.php'));
        $this->assertStringNotContainsString('wire:click="applyGoldenConfiguration"', $view);
        $this->assertStringNotContainsString('Ghi đè bằng Cấu hình chuẩn', $view);
        $this->assertStringContainsString('Ghi đè cấu hình hiện có bằng Golden Configuration', $view);
        $this->assertStringContainsString('wire:click="nextStep"', $view);
        $this->assertStringContainsString('Đang áp dụng cấu hình…', $view);

        $source = (string) file_get_contents((new \ReflectionClass(QuickSetupWizard::class))->getFileName());
        $this->assertStringContainsString('completeStep2BeforeAdvance', $source);
        $this->assertStringContainsString('overwriteGoldenConfiguration = true', $source);
    }

    /**
     * @param  callable(): array<string, mixed>  $responder
     * @return object{calls: list<array{userId: int, root: ?string, overwrite: bool}>}
     */
    private function bindFakeGoldenBootstrap(callable $responder): object
    {
        $fake = new class($responder) {
            /** @var list<array{userId: int, root: ?string, overwrite: bool}> */
            public array $calls = [];

            /** @param callable(): array<string, mixed> $responder */
            public function __construct(private $responder) {}

            public function bootstrap(int $userId, ?string $targetRoot = null, bool $overwriteExisting = false): array
            {
                $this->calls[] = [
                    'userId' => $userId,
                    'root' => $targetRoot,
                    'overwrite' => $overwriteExisting,
                ];

                return ($this->responder)();
            }
        };

        $this->app->instance(
            \Omnichannel\Addons\Seo\Services\ConfigurationSync\GoldenBootstrapService::class,
            $fake,
        );

        return $fake;
    }

    public function test_server_connection_test_success_with_health_ping(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'http://localhost:3000/api/health' => \Illuminate\Support\Facades\Http::response(['status' => 'ok'], 200),
        ]);

        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $testComponent->set('serverUrl', 'http://localhost:3000')
            ->call('testServerConnection')
            ->assertSet('serverConnectionStatus', 'success')
            ->assertSet('serverUrl', 'http://localhost:3000');

        $this->assertSame('http://localhost:3000', WpOption::get('h1_control_server_url'));
    }

    public function test_server_connection_test_rejects_non_loopback_http(): void
    {
        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $testComponent->set('serverUrl', 'http://ops.remote-server.com')
            ->call('testServerConnection')
            ->assertSet('serverConnectionStatus', 'error');

        $message = (string) $testComponent->get('serverConnectionMessage');
        $this->assertStringContainsString('HTTPS', $message);
    }

    public function test_server_connection_test_rejects_private_network_ssrf(): void
    {
        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $testComponent->set('serverUrl', 'https://192.168.1.50:8000')
            ->call('testServerConnection')
            ->assertSet('serverConnectionStatus', 'error');

        $message = (string) $testComponent->get('serverConnectionMessage');
        $this->assertStringContainsString('IP', $message);
    }

    public function test_enrollment_rejects_token_violating_ops_server_contract(): void
    {
        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $testComponent->set('serverUrl', 'http://localhost:3000')
            ->set('enrollmentToken', 'tok_invalid with spaces!')
            ->call('activateEnrollment')
            ->assertSet('enrollmentSuccess', false);

        $error = (string) $testComponent->get('enrollmentError');
        $this->assertStringContainsString('Mã kích hoạt không đúng định dạng', $error);
    }

    public function test_enrollment_success_persists_chosen_server_url_and_locks_read_only(): void
    {
        $this->app->singleton(
            \App\Services\H1\Enrollment\EnrollmentTransport::class,
            \App\Services\H1\Enrollment\HttpEnrollmentTransport::class,
        );

        \Illuminate\Support\Facades\Http::fake([
            'http://localhost:3000/api/v1/enrollment/consume' => \Illuminate\Support\Facades\Http::response([
                'success' => true,
                'installation_id' => 'ins_qs_test_01',
                'product' => 'seo-ops',
                'credential' => [
                    'id' => 'cred_qs_test_01',
                    'version' => 1,
                    'secret' => 'unit-test-secret-value-12345',
                ],
                'entitlement' => [
                    'revision' => 1,
                    'services' => [
                        'seo' => ['enabled' => true, 'quota' => 10],
                    ],
                ],
            ], 200),
        ]);

        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $testComponent->set('serverUrl', 'http://localhost:3000')
            ->set('enrollmentToken', 'tok_abcdef1234567890abcdef')
            ->call('activateEnrollment')
            ->assertSet('enrollmentSuccess', true)
            ->assertSet('isEnrolled', true);

        // Verify bound in database
        $installation = \App\Models\H1Installation::where('installation_id', 'ins_qs_test_01')->first();
        $this->assertNotNull($installation);
        $this->assertSame('http://localhost:3000', $installation->control_server_url);

        // Reopening preserves binding and locks editing
        $reopened = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $reopened->assertSet('isEnrolled', true);
        $this->assertSame('http://localhost:3000', $reopened->get('installationDetails')['control_server_url']);

        // Attempting to migrate/change server while enrolled is blocked
        $reopened->set('serverUrl', 'https://other-server.example.com')
            ->call('activateEnrollment')
            ->assertSet('enrollmentSuccess', false);

        $error = (string) $reopened->get('enrollmentError');
        $this->assertStringContainsString('phục hồi ủy quyền', $error);
    }

    public function test_reconcile_and_golden_sync_use_bound_server_url_and_env_does_not_overwrite(): void
    {
        $installation = \App\Models\H1Installation::create([
            'installation_id' => 'ins_bound_01',
            'product' => 'seo-ops',
            'control_server_url' => 'http://localhost:3000',
            'status' => 'enrolled',
            'cutover_mode' => 'pre_enrollment',
            'credential_version' => 1,
            'entitlement_revision' => 1,
        ]);

        $cred = new \App\Models\H1Credential();
        $cred->installation_id = 'ins_bound_01';
        $cred->credential_id = 'cred_bound_01';
        $cred->version = 1;
        $cred->status = \App\Models\H1Credential::STATUS_ACTIVE;
        $secret = \App\Services\H1\Protocol\LicenseCrypto::base64UrlEncode(random_bytes(32));
        $cred->setSecretBase64Url($secret);
        $cred->save();

        // Config has a different URL, but installation is already bound to http://localhost:3000
        config(['h1-license.base_url' => 'https://conflicting-env.example.com']);

        \Illuminate\Support\Facades\Http::fake([
            'http://localhost:3000/api/v1/installations/ins_bound_01/license/reconcile' => \Illuminate\Support\Facades\Http::response([
                'success' => false,
                'code' => 'server_offline',
                'detail' => 'Simulated offline in test',
            ], 500),
            'http://localhost:3000/api/v1/installations/ins_bound_01/services/seo/config/package' => \Illuminate\Support\Facades\Http::response([
                'success' => true,
                'package_id' => 'seo-ops-golden-configuration',
                'files' => [],
            ], 200),
        ]);

        // Reconcile attempts outbound call
        $reconcileService = app(\App\Services\H1\Reconcile\LicenseReconcileService::class);
        try {
            $reconcileService->reconcile();
        } catch (\Throwable) {
        }

        // Verify reconcile called the bound localhost:3000 and NEVER called conflicting .env!
        \Illuminate\Support\Facades\Http::assertSent(function ($request): bool {
            return str_starts_with($request->url(), 'http://localhost:3000/api/v1/installations/ins_bound_01/license/reconcile');
        });
        \Illuminate\Support\Facades\Http::assertNotSent(function ($request): bool {
            return str_contains($request->url(), 'conflicting-env.example.com');
        });

        // Verify installation's control_server_url was NOT overwritten by conflicting .env!
        $installation->refresh();
        $this->assertSame('http://localhost:3000', $installation->control_server_url);

        // Golden sync transport uses the bound URL
        $transport = app(\Omnichannel\Addons\Seo\Services\ConfigurationSync\ConfigSyncHttpTransport::class);
        try {
            $transport->package();
        } catch (\Throwable) {
        }

        \Illuminate\Support\Facades\Http::assertSent(function ($request): bool {
            return str_starts_with($request->url(), 'http://localhost:3000/api/v1/installations/ins_bound_01/services/seo/config/package');
        });
    }
}
