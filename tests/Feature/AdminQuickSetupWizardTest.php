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

    public function test_enrolled_without_grants_keeps_commercial_list_empty(): void
    {
        $this->seedEnrolledCommercial([
            'agent' => ['key' => 'agent', 'scope' => 'global', 'enabled' => true, 'quota' => null],
            'seo' => [
                'key' => 'seo',
                'scope' => 'licensed',
                'license_model' => 'domain_quota',
                'enabled' => false,
                'quota' => null,
                'revoked_at' => null,
            ],
            'seeding' => [
                'key' => 'seeding',
                'scope' => 'licensed',
                'license_model' => 'flat',
                'enabled' => false,
                'quota' => null,
                'revoked_at' => null,
            ],
        ]);

        Livewire::actingAs($this->owner)
            ->test(QuickSetupWizard::class)
            ->assertSet('hasCommercialServiceEnabled', false)
            ->assertSet('commercialServices', [])
            ->assertSee('Chưa có entitlement thương mại nào được cấp');
    }

    public function test_step_navigation_and_validation(): void
    {
        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);

        // Step 1 initial — no commercial services → cannot advance
        $testComponent->assertSet('currentStep', 1)
            ->assertSet('hasCommercialServiceEnabled', false);

        $testComponent->set('enrollmentToken', 'invalid_no_prefix')
            ->call('activateEnrollment')
            ->assertSet('enrollmentSuccess', false);

        $testComponent->call('goToStep', 2)
            ->assertSet('currentStep', 1)
            ->assertSet('commercialGateMessage', QuickSetupWizard::COMMERCIAL_GATE_MESSAGE);

        $this->seedEnrolledCommercial([
            'seo' => ['key' => 'seo', 'enabled' => true, 'quota' => 5],
        ]);

        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $testComponent->assertSet('hasCommercialServiceEnabled', true);

        $testComponent->call('goToStep', 2)
            ->assertSet('currentStep', 2);

        $testComponent->call('goToStep', 3)
            ->assertSet('currentStep', 3);

        $testComponent->set('aiApiKey', '')
            ->call('testAiConnection')
            ->assertSet('aiTestStatus', 'error');

        $testComponent->call('goToStep', 4)
            ->assertSet('currentStep', 4);
        $testComponent->call('goToStep', 5)
            ->assertSet('currentStep', 4);

        $checklist = $testComponent->get('checklist');
        $this->assertIsArray($checklist);
        $this->assertGreaterThanOrEqual(5, count($checklist));

        $testComponent->call('finishQuickSetup');
        $this->assertNotEmpty(WpOption::get('quick_setup_completed_at'));
    }

    public function test_enrolled_without_commercial_service_blocks_step1_continue(): void
    {
        $this->seedEnrolledCommercial([
            'agent' => ['key' => 'agent', 'enabled' => true, 'scope' => 'global'],
            'seo' => ['key' => 'seo', 'enabled' => false],
            'seeding' => ['key' => 'seeding', 'enabled' => false],
            'social-automation' => ['key' => 'social-automation', 'enabled' => false],
        ]);

        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $testComponent->assertSet('isEnrolled', true)
            ->assertSet('hasCommercialServiceEnabled', false)
            ->call('nextStep')
            ->assertSet('currentStep', 1)
            ->assertSet('commercialGateMessage', QuickSetupWizard::COMMERCIAL_GATE_MESSAGE);

        $testComponent->call('goToStep', 3)->assertSet('currentStep', 1);
        $testComponent->set('currentStep', 2)->assertSet('currentStep', 1);
    }

    public function test_core_agent_enabled_alone_does_not_unlock_step1(): void
    {
        $this->seedEnrolledCommercial([
            'agent' => ['key' => 'agent', 'enabled' => true],
        ]);

        Livewire::actingAs($this->owner)->test(QuickSetupWizard::class)
            ->assertSet('hasCommercialServiceEnabled', false)
            ->call('goToStep', 2)
            ->assertSet('currentStep', 1);
    }

    public function test_url_step_query_cannot_bypass_commercial_gate(): void
    {
        $this->seedEnrolledCommercial([
            'seo' => ['key' => 'seo', 'enabled' => false],
        ]);

        Livewire::actingAs($this->owner)
            ->withQueryParams(['step' => 3])
            ->test(QuickSetupWizard::class)
            ->assertSet('currentStep', 1)
            ->assertSet('hasCommercialServiceEnabled', false);
    }

    public function test_seeding_only_passes_step1_downloads_without_seo_apply(): void
    {
        $this->seedEnrolledCommercial([
            'seeding' => ['key' => 'seeding', 'enabled' => true],
            'seo' => ['key' => 'seo', 'enabled' => false],
        ]);
        $this->bindSeoEntitlementProbe(false);
        $fake = $this->bindFakeGoldenBootstrap(static fn (): array => [
            'status' => 'bootstrapped',
            'imported' => true,
        ]);

        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $testComponent->assertSet('hasCommercialServiceEnabled', true)
            ->call('nextStep')
            ->assertSet('currentStep', 2)
            ->assertSet('goldenSeoEntitled', false)
            ->assertSet('goldenSuccess', false);

        $downloadCalls = array_values(array_filter($fake->calls, static fn (array $c): bool => ($c['op'] ?? '') === 'download'));
        $applyCalls = array_values(array_filter($fake->calls, static fn (array $c): bool => ($c['op'] ?? '') === 'apply'));
        $this->assertGreaterThanOrEqual(1, count($downloadCalls));
        $this->assertCount(0, $applyCalls);

        $testComponent->call('nextStep')->assertSet('currentStep', 3);
        $applyAfter = array_values(array_filter($fake->calls, static fn (array $c): bool => ($c['op'] ?? '') === 'apply'));
        $this->assertCount(0, $applyAfter);
    }

    public function test_social_only_passes_step1_downloads_without_seo_apply(): void
    {
        $this->seedEnrolledCommercial([
            'social-automation' => ['key' => 'social-automation', 'enabled' => true],
            'seo' => ['key' => 'seo', 'enabled' => false],
        ]);
        $this->bindSeoEntitlementProbe(false);
        $fake = $this->bindFakeGoldenBootstrap(static fn (): array => [
            'status' => 'bootstrapped',
            'imported' => true,
        ]);

        Livewire::actingAs($this->owner)->test(QuickSetupWizard::class)
            ->call('nextStep')
            ->assertSet('currentStep', 2)
            ->assertSet('goldenSeoEntitled', false);

        $applyCalls = array_values(array_filter($fake->calls, static fn (array $c): bool => ($c['op'] ?? '') === 'apply'));
        $this->assertCount(0, $applyCalls);
    }

    public function test_h1_unacked_entitlement_not_reported_enabled(): void
    {
        $this->seedEnrolledCommercial([]);
        $installation = \App\Models\H1Installation::query()->first();
        $this->assertNotNull($installation);
        $installation->verified_snapshot = null;
        $installation->validated_at = null;
        $installation->entitlement_revision = 0;
        $installation->save();

        Livewire::actingAs($this->owner)->test(QuickSetupWizard::class)
            ->assertSet('isEnrolled', true)
            ->assertSet('hasCommercialServiceEnabled', false);
    }

    public function test_step1_auto_reconcile_runs_once_and_refreshes_commercial(): void
    {
        $this->seedEnrolledCommercial([
            'seo' => ['key' => 'seo', 'enabled' => false],
        ]);
        $reconcile = $this->bindFakeReconcile(function (): array {
            $installation = \App\Models\H1Installation::query()->first();
            $this->assertNotNull($installation);
            $installation->verified_snapshot = [
                'services' => [
                    'seo' => ['key' => 'seo', 'enabled' => true, 'quota' => 3],
                    'seeding' => ['key' => 'seeding', 'enabled' => false],
                    'social-automation' => ['key' => 'social-automation', 'enabled' => false],
                ],
            ];
            $installation->validated_at = now();
            $installation->save();

            return ['commands_applied' => 1, 'acks' => [], 'offline' => false];
        });

        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $testComponent->call('maybeAutoReconcileOnStep1')
            ->assertSet('step1AutoReconcileCompleted', true)
            ->assertSet('reconcileRunning', false)
            ->assertSet('reconcileError', null)
            ->assertSet('hasCommercialServiceEnabled', true);

        $this->assertSame(1, $reconcile->calls);

        $testComponent->call('maybeAutoReconcileOnStep1');
        $this->assertSame(1, $reconcile->calls, 'auto-reconcile must not repeat on rerender');

        $testComponent->call('nextStep')->assertSet('currentStep', 2);
    }

    public function test_step1_auto_reconcile_offline_shows_error_and_retry(): void
    {
        $this->seedEnrolledCommercial([
            'seo' => ['key' => 'seo', 'enabled' => true, 'quota' => 2],
        ]);
        $reconcile = $this->bindFakeReconcile(static fn (): array => [
            'commands_applied' => 0,
            'acks' => [],
            'offline' => true,
        ]);

        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $testComponent->call('maybeAutoReconcileOnStep1')
            ->assertSet('step1AutoReconcileCompleted', true)
            ->assertSet('connectionState', 'CONNECTION_ERROR')
            ->assertSet('hasCommercialServiceEnabled', true)
            ->assertSee('Thử lại')
            ->assertDontSee('Recovery Mode');

        $error = (string) $testComponent->get('reconcileError');
        $this->assertStringContainsString('OPS-SERVER', $error);
        $this->assertStringContainsString('giữ nguyên', $error);
        $this->assertSame(1, $reconcile->calls);

        $testComponent->call('reconcileLicense');
        $this->assertSame(2, $reconcile->calls, 'Retry must call reconcile again');
    }

    public function test_step1_reconcile_timeout_sets_connection_error(): void
    {
        $this->seedEnrolledCommercial([
            'seeding' => ['key' => 'seeding', 'enabled' => true],
        ]);
        $this->bindFakeReconcile(static function (): array {
            throw new \RuntimeException('cURL error 28: Operation timed out');
        });

        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $testComponent->call('maybeAutoReconcileOnStep1')
            ->assertSet('connectionState', 'CONNECTION_ERROR')
            ->assertSet('hasCommercialServiceEnabled', true);

        $error = (string) $testComponent->get('reconcileError');
        $this->assertStringContainsString('timeout', strtolower($error));

        $testComponent->call('nextStep')->assertSet('currentStep', 2);
    }

    public function test_step1_installation_revoked_enters_recovery_mode_with_reconnect_form(): void
    {
        $this->seedEnrolledCommercial([
            'seo' => ['key' => 'seo', 'enabled' => true, 'quota' => 10],
        ]);
        $this->bindFakeReconcile(static function (): array {
            throw new \App\Services\H1\Exceptions\H1TransportException(
                'installation_revoked',
                'E2E TEST 1: manual revoke — Client must reconnect with a new Enrollment token'
            );
        });

        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $testComponent->call('maybeAutoReconcileOnStep1')
            ->assertSet('connectionState', 'INSTALLATION_REVOKED')
            ->assertSet('hasCommercialServiceEnabled', false)
            ->assertSee('Recovery Mode')
            ->assertSee('Kết nối lại OPS-SERVER')
            ->assertSee('Enrollment Token mới')
            ->assertSee('snapshot cũ', false)
            ->assertDontSee('Thiết bị đã được kích hoạt với OPS-SERVER');

        $reason = (string) $testComponent->get('connectionReason');
        $this->assertStringContainsString('manual revoke', $reason);

        $installation = \App\Models\H1Installation::query()->first();
        $this->assertNotNull($installation);
        $this->assertNotEmpty($installation->verified_snapshot['revoked_at'] ?? null);
        $this->assertNotNull(
            \App\Models\H1Credential::query()
                ->where('installation_id', $installation->installation_id)
                ->where('status', \App\Models\H1Credential::STATUS_ACTIVE)
                ->first(),
            'Network-style revoke must not wipe local credential before reconnect'
        );
    }

    public function test_step1_continue_locked_while_reconcile_running(): void
    {
        $this->seedEnrolledCommercial([
            'seo' => ['key' => 'seo', 'enabled' => true],
        ]);
        $this->bindFakeReconcile();

        Livewire::actingAs($this->owner)->test(QuickSetupWizard::class)
            ->set('reconcileRunning', true)
            ->set('hasCommercialServiceEnabled', true)
            ->call('nextStep')
            ->assertSet('currentStep', 1);
    }

    public function test_step1_no_commercial_after_reconcile_keeps_continue_blocked(): void
    {
        $this->seedEnrolledCommercial([
            'agent' => ['key' => 'agent', 'enabled' => true],
            'seo' => ['key' => 'seo', 'enabled' => false],
        ]);
        $this->bindFakeReconcile(static fn (): array => [
            'commands_applied' => 0,
            'acks' => [],
            'offline' => false,
        ]);

        Livewire::actingAs($this->owner)->test(QuickSetupWizard::class)
            ->call('maybeAutoReconcileOnStep1')
            ->assertSet('hasCommercialServiceEnabled', false)
            ->assertSet('commercialGateMessage', QuickSetupWizard::COMMERCIAL_GATE_MESSAGE)
            ->call('nextStep')
            ->assertSet('currentStep', 1);
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

    public function test_dashboard_recognizes_verified_license_in_pre_enrollment_mode(): void
    {
        WpOption::set('quick_setup_completed_at', now()->toIso8601String());
        WpOption::clearRequestCache();

        config(['h1-license.enforcement_mode' => 'pre_enrollment']);
        \App\Models\H1Installation::create([
            'installation_id' => 'inst_pre_enrollment_licensed',
            'product' => 'seo-ops',
            'control_server_url' => 'https://ops.example.com',
            'status' => 'active',
            'cutover_mode' => 'pre_enrollment',
            'verified_snapshot' => [
                'valid_until' => now()->addYear()->utc()->format('Y-m-d\TH:i:s\Z'),
                'services' => [
                    'social-automation' => [
                        'enabled' => true,
                        'valid_until' => now()->addYear()->utc()->format('Y-m-d\TH:i:s\Z'),
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($this->owner)->get('/admin');

        $response->assertStatus(200);
        $response->assertDontSee('Chưa kích hoạt bản quyền thương mại');
    }

    public function test_quick_setup_prefills_existing_server_url(): void
    {
        WpOption::set('h1_control_server_url', 'http://localhost:3000');
        WpOption::clearRequestCache();

        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $testComponent->assertSet('serverUrl', 'http://localhost:3000');
    }

    public function test_step2_seo_enabled_auto_downloads_and_applies_on_blank(): void
    {
        $this->seedEnrolledCommercial([
            'seo' => ['key' => 'seo', 'enabled' => true, 'quota' => 10],
        ]);
        $this->bindSeoEntitlementProbe(true);
        $this->bindBlankAssessment(true);
        $fake = $this->bindFakeGoldenBootstrap(static fn (): array => [
            'status' => 'bootstrapped',
            'imported' => true,
        ]);

        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $testComponent->call('nextStep')
            ->assertSet('currentStep', 2)
            ->assertSet('goldenSuccess', true);

        $downloadCalls = array_values(array_filter($fake->calls, static fn (array $c): bool => ($c['op'] ?? '') === 'download'));
        $applyCalls = array_values(array_filter($fake->calls, static fn (array $c): bool => ($c['op'] ?? '') === 'apply'));
        $this->assertGreaterThanOrEqual(1, count($downloadCalls));
        $this->assertGreaterThanOrEqual(1, count($applyCalls));
        $this->assertTrue($applyCalls[0]['overwrite']);
    }

    public function test_step2_continue_checked_runs_bootstrap_and_advances_on_success(): void
    {
        $this->seedEnrolledCommercial([
            'seo' => ['key' => 'seo', 'enabled' => true],
        ]);
        $this->bindSeoEntitlementProbe(true);
        $this->bindBlankAssessment(true);
        $fake = $this->bindFakeGoldenBootstrap(static fn (): array => [
            'status' => 'bootstrapped',
            'imported' => true,
        ]);

        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $testComponent->call('goToStep', 2);
        $fake->calls = [];
        $testComponent->set('goldenSeoEntitled', true)
            ->set('goldenStatus', 'blank')
            ->set('goldenSuccess', false)
            ->set('overwriteGoldenConfiguration', true)
            ->set('goldenPackageStatus', 'pending_apply')
            ->call('nextStep')
            ->assertSet('currentStep', 3)
            ->assertSet('goldenSuccess', true)
            ->assertSet('goldenError', null);

        $applyCalls = array_values(array_filter($fake->calls, static fn (array $c): bool => ($c['op'] ?? '') === 'apply'));
        $this->assertCount(1, $applyCalls);
        $this->assertTrue($applyCalls[0]['overwrite']);
    }

    public function test_step2_customized_does_not_overwrite_without_consent(): void
    {
        $this->seedEnrolledCommercial([
            'seo' => ['key' => 'seo', 'enabled' => true],
        ]);
        $this->bindSeoEntitlementProbe(true);
        $this->bindBlankAssessment(false);
        $fake = $this->bindFakeGoldenBootstrap(static fn (): array => [
            'status' => 'bootstrapped',
            'imported' => true,
        ]);

        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $testComponent->call('goToStep', 2);
        $fake->calls = [];
        $testComponent->set('goldenSeoEntitled', true)
            ->set('goldenStatus', 'customized')
            ->set('overwriteGoldenConfiguration', false)
            ->set('goldenPackageStatus', 'pending_apply')
            ->call('nextStep')
            ->assertSet('currentStep', 3)
            ->assertSet('goldenError', null);

        $applyCalls = array_values(array_filter($fake->calls, static fn (array $c): bool => ($c['op'] ?? '') === 'apply'));
        $this->assertCount(0, $applyCalls);
    }

    public function test_step2_continue_checked_stays_on_step_on_bootstrap_failure(): void
    {
        $this->seedEnrolledCommercial([
            'seo' => ['key' => 'seo', 'enabled' => true],
        ]);
        $this->bindSeoEntitlementProbe(true);
        $this->bindBlankAssessment(true);
        $fake = $this->bindFakeGoldenBootstrap(static fn (): array => [
            'status' => 'download_failed',
            'imported' => false,
            'error_code' => 'server_offline',
        ]);

        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $testComponent->call('goToStep', 2);
        $fake->calls = [];
        $fake->local = [
            'package_id' => 'seo-ops-golden-configuration',
            'package_version' => '1.0.0',
            'canonical_hash' => str_repeat('a', 64),
        ];
        $testComponent->set('goldenSeoEntitled', true)
            ->set('goldenStatus', 'blank')
            ->set('overwriteGoldenConfiguration', true)
            ->set('goldenPackageStatus', 'pending_apply')
            ->call('nextStep')
            ->assertSet('currentStep', 2)
            ->assertSet('goldenSuccess', false);

        $error = (string) $testComponent->get('goldenError');
        $this->assertStringContainsString('server_offline', $error);
        $applyCalls = array_values(array_filter($fake->calls, static fn (array $c): bool => ($c['op'] ?? '') === 'apply'));
        $this->assertCount(1, $applyCalls);
    }

    public function test_step2_continue_duplicate_submission_is_ignored_while_applying(): void
    {
        $this->seedEnrolledCommercial([
            'seo' => ['key' => 'seo', 'enabled' => true],
        ]);
        $this->bindSeoEntitlementProbe(true);
        $fake = $this->bindFakeGoldenBootstrap(static fn (): array => [
            'status' => 'bootstrapped',
            'imported' => true,
        ]);

        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $testComponent->call('goToStep', 2);
        $fake->calls = [];
        $testComponent->set('goldenSeoEntitled', true)
            ->set('goldenStatus', 'blank')
            ->set('overwriteGoldenConfiguration', true)
            ->set('goldenApplying', true)
            ->call('nextStep')
            ->assertSet('currentStep', 2);

        $this->assertCount(0, $fake->calls);
    }

    public function test_step2_enrolled_but_unlicensed_skips_bootstrap_and_does_not_mark_synced(): void
    {
        $this->seedEnrolledCommercial([
            'seeding' => ['key' => 'seeding', 'enabled' => true],
            'seo' => ['key' => 'seo', 'enabled' => false],
        ]);
        $this->bindSeoEntitlementProbe(false);
        $fake = $this->bindFakeGoldenBootstrap(static fn (): array => [
            'status' => 'bootstrapped',
            'imported' => true,
        ]);

        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $testComponent->call('goToStep', 2);
        $fake->calls = array_values(array_filter($fake->calls, static fn (array $c): bool => ($c['op'] ?? '') === 'download'));
        $testComponent->set('overwriteGoldenConfiguration', true)
            ->call('nextStep')
            ->assertSet('currentStep', 3)
            ->assertSet('goldenSuccess', false)
            ->assertSet('goldenStatus', 'unlicensed')
            ->assertSet('goldenError', null)
            ->assertSet('goldenSeoEntitled', false);

        $applyCalls = array_values(array_filter($fake->calls, static fn (array $c): bool => ($c['op'] ?? '') === 'apply'));
        $this->assertCount(0, $applyCalls);
        $this->assertFalse((bool) $testComponent->get('goldenSuccess'));
    }

    public function test_apply_golden_treats_unlicensed_service_response_as_pending_skip(): void
    {
        $this->seedEnrolledCommercial([
            'seo' => ['key' => 'seo', 'enabled' => true],
        ]);
        $this->bindSeoEntitlementProbe(true);
        $fake = $this->bindFakeGoldenBootstrap(static fn (): array => [
            'status' => 'unlicensed_service',
            'imported' => false,
            'live_integration' => 'BLOCKED_ON_H1',
        ]);

        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $testComponent->call('goToStep', 2);
        $fake->calls = [];
        $testComponent->set('goldenSeoEntitled', true)
            ->set('goldenStatus', 'blank')
            ->set('overwriteGoldenConfiguration', true);

        $allowed = $testComponent->instance()->applyGoldenConfiguration();
        $this->assertTrue($allowed);
        $testComponent->assertSet('goldenSuccess', false)
            ->assertSet('goldenStatus', 'unlicensed')
            ->assertSet('goldenError', null);
        $applyCalls = array_values(array_filter($fake->calls, static fn (array $c): bool => ($c['op'] ?? '') === 'apply'));
        $this->assertCount(1, $applyCalls);
    }

    public function test_retry_download_is_idempotent_when_package_already_local(): void
    {
        $this->seedEnrolledCommercial([
            'seo' => ['key' => 'seo', 'enabled' => true],
        ]);
        $this->bindSeoEntitlementProbe(true);
        $fake = $this->bindFakeGoldenBootstrap(static fn (): array => [
            'status' => 'bootstrapped',
            'imported' => true,
        ]);
        $fake->downloadResponder = static fn (): array => [
            'status' => 'already_downloaded',
            'downloaded' => false,
            'imported' => false,
            'package_version' => '1.0.0',
            'canonical_hash' => str_repeat('a', 64),
            'apply_status' => 'pending_apply',
        ];
        $fake->local = [
            'package_id' => 'seo-ops-golden-configuration',
            'package_version' => '1.0.0',
            'canonical_hash' => str_repeat('a', 64),
        ];

        $testComponent = Livewire::actingAs($this->owner)->test(QuickSetupWizard::class);
        $testComponent->call('goToStep', 2);
        $fake->calls = [];
        $testComponent->call('downloadGoldenPackage')
            ->assertSet('goldenPackageStatus', 'pending_apply');

        $this->assertCount(1, array_filter($fake->calls, static fn (array $c): bool => ($c['op'] ?? '') === 'download'));
    }

    public function test_step2_view_separates_download_and_apply_and_wires_continue(): void
    {
        $view = (string) file_get_contents(resource_path('views/filament/pages/quick-setup-wizard.blade.php'));
        $this->assertStringContainsString('wire:click="downloadGoldenPackage"', $view);
        $this->assertStringNotContainsString('wire:click="applyGoldenConfiguration"', $view);
        $this->assertStringNotContainsString('Website đầu tiên', $view);
        $this->assertStringContainsString('Ghi đè cấu hình hiện có bằng Golden Configuration', $view);
        $this->assertStringContainsString('goldenStatus === \'customized\'', $view);
        $this->assertStringContainsString('wire:click="nextStep"', $view);
        $this->assertStringContainsString('Đang xử lý cấu hình…', $view);
        $this->assertStringContainsString('Bước {{ $currentStep }}/4', $view);
        $this->assertStringContainsString('Dịch vụ thương mại', $view);
        $this->assertStringContainsString('Chưa có entitlement thương mại nào được cấp', $view);
        $this->assertStringContainsString('hasCommercialServiceEnabled', $view);
        $this->assertStringContainsString('wire:init="maybeAutoReconcileOnStep1"', $view);
        $this->assertStringContainsString('Thử lại', $view);
        $this->assertStringContainsString('reconcileRunning', $view);

        $source = (string) file_get_contents((new \ReflectionClass(QuickSetupWizard::class))->getFileName());
        $this->assertStringContainsString('completeStep2BeforeAdvance', $source);
        $this->assertStringContainsString('runAutomaticGoldenLifecycle', $source);
        $this->assertStringContainsString('canContinuePastCommercialGate', $source);
        $this->assertStringContainsString('maybeAutoReconcileOnStep1', $source);
        $this->assertStringContainsString('runStep1Reconcile', $source);
        $this->assertStringContainsString('downloadPackage', $source);
        $this->assertStringContainsString('applyPackage', $source);
        $this->assertStringNotContainsString('function createSite', $source);
    }

    /**
     * @param  array<string, array<string, mixed>>  $services
     */
    private function seedEnrolledCommercial(array $services): void
    {
        \App\Models\H1Credential::query()->delete();
        \App\Models\H1Installation::query()->delete();

        $installationId = 'ins_qs_commercial_'.bin2hex(random_bytes(2));
        \App\Models\H1Installation::create([
            'installation_id' => $installationId,
            'product' => 'seo-ops',
            'control_server_url' => 'http://localhost:3000',
            'status' => 'enrolled',
            'cutover_mode' => 'pre_enrollment',
            'credential_version' => 1,
            'entitlement_revision' => 1,
            'verified_snapshot' => [
                'services' => $services,
            ],
            'validated_at' => now(),
        ]);

        $cred = new \App\Models\H1Credential();
        $cred->installation_id = $installationId;
        $cred->credential_id = 'cred_'.$installationId;
        $cred->version = 1;
        $cred->status = \App\Models\H1Credential::STATUS_ACTIVE;
        $cred->setSecretBase64Url(\App\Services\H1\Protocol\LicenseCrypto::base64UrlEncode(random_bytes(32)));
        $cred->save();

        // Default no-op reconcile so enrolled Step 1 tests do not hit the network.
        $this->bindFakeReconcile();
    }

    /**
     * @param  (callable(): array{commands_applied: int, acks: list<array<string, mixed>>, offline: bool})|null  $responder
     * @return object{calls: int}
     */
    private function bindFakeReconcile(?callable $responder = null): object
    {
        $fake = new class($responder) {
            public int $calls = 0;

            /** @param (callable(): array{commands_applied: int, acks: list<array<string, mixed>>, offline: bool})|null $responder */
            public function __construct(private $responder) {}

            /**
             * @param  array<string, mixed>|null  $mockedResponse
             * @return array{commands_applied: int, acks: list<array<string, mixed>>, offline: bool}
             */
            public function reconcile(?array $mockedResponse = null): array
            {
                unset($mockedResponse);
                $this->calls++;
                if (is_callable($this->responder)) {
                    return ($this->responder)();
                }

                return ['commands_applied' => 0, 'acks' => [], 'offline' => false];
            }
        };

        $this->app->instance(\App\Services\H1\Reconcile\LicenseReconcileService::class, $fake);

        return $fake;
    }

    private function bindBlankAssessment(bool $blank): void
    {
        $this->app->instance(
            \Omnichannel\Addons\Seo\Services\ConfigurationSync\BlankInstallationDetector::class,
            new class($blank) {
                public function __construct(private bool $blank) {}

                /** @return array{blank: bool} */
                public function assess(int $userId): array
                {
                    return ['blank' => $this->blank];
                }
            },
        );

        if (! $blank) {
            $this->app->instance(
                \Omnichannel\Addons\Seo\Services\ConfigurationSync\ConfigurationSyncCoordinator::class,
                new class {
                    /** @return array{outcome: string} */
                    public function compare(int $userId): array
                    {
                        return ['outcome' => 'customized'];
                    }
                },
            );
        }
    }

    /**
     * @param  callable(): array<string, mixed>  $responder
     * @return object{
     *     calls: list<array{op: string, userId?: int, root?: ?string, overwrite?: bool}>,
     *     local: ?array<string, mixed>,
     *     downloadResponder: ?callable
     * }
     */
    private function bindFakeGoldenBootstrap(callable $responder): object
    {
        $fake = new class($responder) {
            /** @var list<array{op: string, userId?: int, root?: ?string, overwrite?: bool}> */
            public array $calls = [];

            /** @var array<string, mixed>|null */
            public ?array $local = null;

            /** @var (callable(): array<string, mixed>)|null */
            public $downloadResponder = null;

            /** @param callable(): array<string, mixed> $responder */
            public function __construct(private $responder) {}

            public function bootstrap(int $userId, ?string $targetRoot = null, bool $overwriteExisting = false): array
            {
                $this->calls[] = [
                    'op' => 'bootstrap',
                    'userId' => $userId,
                    'root' => $targetRoot,
                    'overwrite' => $overwriteExisting,
                ];

                return ($this->responder)();
            }

            public function downloadPackage(?string $targetRoot = null): array
            {
                $this->calls[] = ['op' => 'download', 'root' => $targetRoot];

                if (is_callable($this->downloadResponder)) {
                    $res = ($this->downloadResponder)();
                } else {
                    $res = [
                        'status' => 'downloaded',
                        'downloaded' => true,
                        'imported' => false,
                        'package_version' => '1.0.0',
                        'canonical_hash' => str_repeat('a', 64),
                        'apply_status' => 'pending_apply',
                    ];
                }

                if (in_array((string) ($res['status'] ?? ''), ['downloaded', 'already_downloaded'], true)) {
                    $this->local = [
                        'package_id' => 'seo-ops-golden-configuration',
                        'package_version' => (string) ($res['package_version'] ?? '1.0.0'),
                        'canonical_hash' => (string) ($res['canonical_hash'] ?? str_repeat('a', 64)),
                    ];
                }

                return $res;
            }

            public function applyPackage(int $userId, ?string $targetRoot = null, bool $overwriteExisting = false): array
            {
                $this->calls[] = [
                    'op' => 'apply',
                    'userId' => $userId,
                    'root' => $targetRoot,
                    'overwrite' => $overwriteExisting,
                ];

                $res = ($this->responder)();
                if (($res['status'] ?? '') === 'bootstrapped') {
                    // Keep local package; apply status tracked via wizard goldenSuccess / state store.
                }

                return $res;
            }

            public function inspectLocalPackage(?string $targetRoot = null): ?array
            {
                return $this->local;
            }
        };

        $this->app->instance(
            \Omnichannel\Addons\Seo\Services\ConfigurationSync\GoldenBootstrapService::class,
            $fake,
        );

        return $fake;
    }

    private function bindSeoEntitlementProbe(bool $enabled): void
    {
        $this->app->instance(
            \Omnichannel\Addons\Seo\Services\ConfigurationSync\SeoEntitlementProbe::class,
            new class($enabled) implements \Omnichannel\Addons\Seo\Services\ConfigurationSync\SeoEntitlementProbe {
                public function __construct(private bool $enabled) {}

                public function seoServiceEnabled(): bool
                {
                    return $this->enabled;
                }
            },
        );
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
        $this->bindFakeReconcile();

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
