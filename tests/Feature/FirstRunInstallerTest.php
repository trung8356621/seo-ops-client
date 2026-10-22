<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Installation\AdminAccountCreator;
use App\Core\Installation\InstallationState;
use App\Core\Installation\InstallationStateManager;
use App\Models\H1Installation;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use App\Services\H1\Enforcement\ServiceEntitlementGate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Omnichannel\Addons\Seo\Livewire\OperationalAlertHook;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\SocialAutomationBootstrapController;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceResolver;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class FirstRunInstallerTest extends TestCase
{
    private string $testInstallerDir;
    private InstallationStateManager $stateManager;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.core_connection', 'sqlite');
        Config::set('database.default', 'sqlite');

        $this->testInstallerDir = storage_path('installer_test_'.bin2hex(random_bytes(4)));
        @mkdir($this->testInstallerDir, 0755, true);

        $this->stateManager = new InstallationStateManager($this->testInstallerDir);
        $this->app->instance(InstallationStateManager::class, $this->stateManager);

        $this->createTestSchema();

        if (class_exists(Livewire::class) && class_exists(OperationalAlertHook::class)) {
            try {
                Livewire::component('operational-alert-hook', OperationalAlertHook::class);
            } catch (\Throwable) {}
        }
    }

    private function createTestSchema(): void
    {
        $conn = 'sqlite';

        foreach ([
            'sites',
            'users',
            'services',
            'h1_installations',
            'prompt_result_routing_attempts',
            'api_connections',
            'ai_runtime_health_states',
        ] as $t) {
            Schema::connection($conn)->dropIfExists($t);
        }

        Schema::connection($conn)->create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('staff');
            $table->string('status')->default('normal');
            $table->boolean('is_system')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection($conn)->create('services', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('addon_namespace')->nullable();
            $table->string('db_connection')->default('mysql');
            $table->boolean('is_active')->default(false);
            $table->json('config')->nullable();
            $table->timestamps();
        });

        Schema::connection($conn)->create('sites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('domain');
            $table->string('status')->default('pending');
            $table->boolean('ssl')->default(false);
            $table->boolean('h1_is_anchor')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection($conn)->create('h1_installations', function (Blueprint $table): void {
            $table->id();
            $table->string('installation_id')->unique();
            $table->string('product');
            $table->string('control_server_url')->nullable();
            $table->string('status')->default('active');
            $table->string('cutover_mode')->default('pre_enrollment');
            $table->json('verified_snapshot')->nullable();
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

        Schema::connection($conn)->create('api_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('provider')->index();
            $table->string('name');
            $table->text('api_key');
            $table->string('default_model')->nullable();
            $table->boolean('is_global')->default(false);
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::connection($conn)->create('ai_runtime_health_states', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testInstallerDir)) {
            File::deleteDirectory($this->testInstallerDir);
        }

        parent::tearDown();
    }

    public function test_1_and_2_unconfigured_state_redirects_root_to_setup(): void
    {
        $this->stateManager->setStatusForTesting(InstallationState::UNCONFIGURED);

        $response = $this->get('/');
        $response->assertRedirect('/setup');
    }

    public function test_unconfigured_state_redirects_all_entry_points_to_setup(): void
    {
        $this->stateManager->setStatusForTesting(InstallationState::UNCONFIGURED);

        foreach (['/login', '/admin', '/admin/login', '/seo', '/seeding'] as $uri) {
            $response = $this->get($uri);
            $response->assertRedirect('/setup');
        }
    }

    public function test_single_database_connection_test_endpoint(): void
    {
        $this->stateManager->setStatusForTesting(InstallationState::UNCONFIGURED);
        $this->withSession(['setup_authorized' => true]);

        // Empty database name -> 422
        $resEmpty = $this->postJson('/setup/api/database/test-single', [
            'label' => 'SEO Content AI',
            'database' => '',
            'host' => '127.0.0.1',
            'port' => 3306,
        ]);
        $resEmpty->assertStatus(422);
        $resEmpty->assertJson(['ok' => false]);
    }

    public function test_setup_page_renders_wizard(): void
    {
        $this->stateManager->setStatusForTesting(InstallationState::UNCONFIGURED);

        $response = $this->get('/setup');
        $response->assertStatus(200);
        $response->assertSee('SEO-OPS Client Setup');
        $response->assertSee('Bước 1 — Cấu hình kết nối MySQL');
    }

    public function test_3_token_verification_and_session_authorization(): void
    {
        $this->stateManager->setStatusForTesting(InstallationState::UNCONFIGURED);

        $token = $this->stateManager->getOrCreateSetupToken();
        $this->assertNotEmpty($token);

        // Verify with invalid token
        $responseBad = $this->postJson('/setup/api/verify-token', ['token' => 'invalid-token']);
        $responseBad->assertStatus(403);
        $responseBad->assertJson(['ok' => false]);

        // Verify with valid token
        $responseGood = $this->postJson('/setup/api/verify-token', ['token' => $token]);
        $responseGood->assertStatus(200);
        $responseGood->assertJson(['ok' => true]);
    }

    public function test_4_configure_database_validates_and_rejects_empty_names(): void
    {
        $this->stateManager->setStatusForTesting(InstallationState::UNCONFIGURED);
        $this->withSession(['setup_authorized' => true]);

        $response = $this->postJson('/setup/api/database', [
            'host' => '127.0.0.1',
            'port' => 3306,
            'username' => 'root',
            'password' => '',
            'db_client' => '',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['ok' => false]);
    }

    public function test_5_admin_creator_validates_and_creates_owner_account(): void
    {
        $this->stateManager->setStatusForTesting(InstallationState::CONFIGURING);
        $creator = new AdminAccountCreator($this->stateManager);

        // Validation failure (short password)
        $fail = $creator->createAdmin([
            'name' => 'Admin Test',
            'email' => 'admin@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);
        $this->assertFalse($fail['ok']);

        // Success creation
        $testEmail = 'fresh_admin_'.bin2hex(random_bytes(4)).'@test.com';
        $success = $creator->createAdmin([
            'name' => 'Fresh Administrator',
            'email' => $testEmail,
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
        ]);

        $this->assertTrue($success['ok'], $success['message'] ?? '');
        $this->assertDatabaseHas('users', [
            'email' => $testEmail,
            'role' => User::ROLE_OWNER,
        ]);

        // Prevent duplicate setup
        $duplicate = $creator->createAdmin([
            'name' => 'Second Admin',
            'email' => 'second@test.com',
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
        ]);
        $this->assertFalse($duplicate['ok']);
        $this->assertStringContainsString('already exists', $duplicate['message']);

        // Clean up created test user
        User::query()->where('email', $testEmail)->delete();
    }

    public function test_6_finish_step_persists_lock_and_unlicensed_state(): void
    {
        $this->stateManager->setStatusForTesting(InstallationState::CONFIGURING);
        $this->withSession(['setup_authorized' => true]);

        $response = $this->postJson('/setup/api/finish');
        $response->assertStatus(200);
        $response->assertJson(['ok' => true]);

        $this->assertTrue($this->stateManager->hasInstalledLock());

        // Re-read with actual state manager (without testing override)
        $this->stateManager->setStatusForTesting(null);
        $this->assertTrue($this->stateManager->isFreshUnlicensed());

        $record = $this->stateManager->readRecord();
        $this->assertSame('installed', $record['status']);
        $this->assertSame('unlicensed', $record['commercial_status']);
        $this->assertFalse($record['h1_enrolled']);
        $this->assertFalse($record['services_activated']);
    }

    public function test_7_8_admin_can_access_admin_panel(): void
    {
        $this->stateManager->markInstalled();

        $admin = User::create([
            'name' => 'Test Owner',
            'email' => 'owner_'.bin2hex(random_bytes(4)).'@test.com',
            'password' => Hash::make('password123'),
            'role' => User::ROLE_OWNER,
            'status' => 'active',
        ]);

        $this->withoutExceptionHandling();
        $response = $this->actingAs($admin)->get('/admin');
        // If Filament admin view succeeds or redirects within admin
        $this->assertTrue(in_array($response->status(), [200, 302], true));
    }

    public function test_9_service_catalog_is_inactive_and_unenrolled(): void
    {
        $this->stateManager->markInstalled();

        $admin = User::create([
            'name' => 'Test Owner',
            'email' => 'owner_'.bin2hex(random_bytes(4)).'@test.com',
            'password' => Hash::make('password123'),
            'role' => User::ROLE_OWNER,
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin)->get('/admin/services');
        $this->assertTrue(in_array($response->status(), [200, 302], true));
        if ($response->status() === 200) {
            $response->assertSee('Chưa liên kết OPS-SERVER');
        }
    }

    public function test_10_licensed_seo_operation_is_denied_safely(): void
    {
        $this->stateManager->markInstalled();
        $this->assertTrue($this->stateManager->isFreshUnlicensed());

        $gate = app(ServiceEntitlementGate::class);
        $this->assertTrue($gate->enforcementActive(), 'Enforcement must be active on a fresh unlicensed install');
        $this->assertFalse($gate->allows('seo'), 'SEO must NOT be allowed on fresh unlicensed install');

        // Test real execution entry point: Site activation must throw exception
        $admin = User::create([
            'name' => 'Test Owner',
            'email' => 'owner_'.bin2hex(random_bytes(4)).'@test.com',
            'password' => Hash::make('password123'),
            'role' => User::ROLE_OWNER,
            'status' => 'active',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SEO service is not licensed for activation.');

        $site = new Site([
            'domain' => 'unlicensed-test-'.bin2hex(random_bytes(4)).'.com',
            'status' => 'active',
            'user_id' => $admin->id,
        ]);
        $site->save();
    }

    public function test_11_licensed_automation_operation_is_denied_safely(): void
    {
        $this->stateManager->markInstalled();
        $this->assertTrue($this->stateManager->isFreshUnlicensed());

        $admin = User::create([
            'name' => 'Test Owner',
            'email' => 'owner_'.bin2hex(random_bytes(4)).'@test.com',
            'password' => Hash::make('password123'),
            'role' => User::ROLE_OWNER,
            'status' => 'active',
        ]);

        // Real execution entry point: checking service resolver & access
        $resolver = app(SocialAutomationServiceResolver::class);
        $this->assertFalse($resolver->isActive(), 'Social Automation must be inactive on fresh install');

        $access = app(SocialAutomationAccess::class);
        $this->assertFalse($access->canAccess($admin), 'Social Automation access must be denied for admin');

        // Invoking controller entry point directly returns 403
        $this->actingAs($admin);
        $controller = app(SocialAutomationBootstrapController::class);

        try {
            $controller();
            $this->fail('Expected 403 HttpException was not thrown');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_12_confirm_no_h1_identity_or_credentials_created(): void
    {
        $this->stateManager->markInstalled();

        $this->assertSame(0, H1Installation::count());
        $this->assertNull(app(\App\Services\H1\Persistence\InstallationStateRepository::class)->currentInstallation());
    }

    public function test_13_reload_setup_remains_locked_after_installation(): void
    {
        $this->stateManager->markInstalled();

        $response = $this->get('/setup');
        $response->assertRedirect('/admin');

        $responseApi = $this->postJson('/setup/api/database', []);
        $responseApi->assertStatus(403);
    }

    public function test_14_and_15_database_outage_shows_maintenance_rather_than_setup(): void
    {
        $this->stateManager->markInstalled();

        // Simulate database outage
        $this->stateManager->setStatusForTesting(InstallationState::DATABASE_UNAVAILABLE);

        $response = $this->get('/');
        $response->assertStatus(503);
        $response->assertSee('Database Unavailable');

        // Restore database
        $this->stateManager->setStatusForTesting(InstallationState::INSTALLED);

        $responseRestored = $this->get('/admin');
        $this->assertNotSame(503, $responseRestored->status());
    }
}
