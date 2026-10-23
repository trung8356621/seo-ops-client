<?php

declare(strict_types=1);

namespace Tests\Unit\Installation;

use App\Core\Installation\InstallationState;
use App\Core\Installation\InstallationStateManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class InstallationStateManagerResolveTest extends TestCase
{
    private string $testInstallerDir;

    private InstallationStateManager $stateManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testInstallerDir = storage_path('installer_resolve_test_'.bin2hex(random_bytes(4)));
        @mkdir($this->testInstallerDir, 0755, true);

        $this->stateManager = new InstallationStateManager($this->testInstallerDir);
        $this->app->instance(InstallationStateManager::class, $this->stateManager);

        Config::set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        Config::set('database.core_connection', 'mysql');
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'seo_ops_fresh_setup_missing_db_'.bin2hex(random_bytes(3)),
            'username' => 'root',
            'password' => '',
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testInstallerDir)) {
            File::deleteDirectory($this->testInstallerDir);
        }

        parent::tearDown();
    }

    public function test_fresh_uninstalled_with_missing_database_is_unconfigured_not_outage(): void
    {
        $this->assertFalse($this->stateManager->hasInstalledLock());
        $this->assertFalse($this->stateManager->probeDatabaseReachable());

        $this->assertSame(InstallationState::UNCONFIGURED, $this->stateManager->currentStatus());
    }

    public function test_configuring_with_missing_database_stays_configuring(): void
    {
        $this->stateManager->saveRecord([
            'status' => 'configuring',
            'step' => 'database',
        ]);

        $this->assertFalse($this->stateManager->probeDatabaseReachable());
        $this->assertSame(InstallationState::CONFIGURING, $this->stateManager->currentStatus());
    }

    public function test_installed_lock_with_missing_database_is_outage(): void
    {
        $this->stateManager->ensureDirectory();
        file_put_contents($this->stateManager->lockFilePath(), json_encode([
            'installed_at' => date('c'),
            'client_version' => '1.0.0',
            'fresh_unlicensed' => true,
        ]));

        $this->assertFalse($this->stateManager->probeDatabaseReachable());
        $this->assertSame(InstallationState::DATABASE_UNAVAILABLE, $this->stateManager->currentStatus());
    }

    public function test_state_installed_without_lock_and_missing_database_is_outage(): void
    {
        $this->stateManager->saveRecord([
            'status' => 'installed',
            'step' => 'completed',
        ]);

        $this->assertFalse($this->stateManager->hasInstalledLock());
        $this->assertFalse($this->stateManager->probeDatabaseReachable());
        $this->assertSame(InstallationState::DATABASE_UNAVAILABLE, $this->stateManager->currentStatus());
    }

    public function test_unconfigured_http_redirects_to_setup_when_database_missing(): void
    {
        $this->assertSame(InstallationState::UNCONFIGURED, $this->stateManager->currentStatus());

        $response = $this->get('/');
        $response->assertRedirect('/setup');

        $setup = $this->get('/setup');
        $setup->assertStatus(200);
        $setup->assertSee('SEO-OPS Client Setup');
    }

    public function test_installed_outage_http_returns_503_not_setup(): void
    {
        $this->stateManager->ensureDirectory();
        file_put_contents($this->stateManager->lockFilePath(), json_encode([
            'installed_at' => date('c'),
            'client_version' => '1.0.0',
            'fresh_unlicensed' => true,
        ]));

        $this->assertSame(InstallationState::DATABASE_UNAVAILABLE, $this->stateManager->currentStatus());

        $response = $this->get('/');
        $response->assertStatus(503);
        $response->assertSee('Database Unavailable');

        $setup = $this->get('/setup');
        $setup->assertStatus(503);
        $setup->assertSee('Database Unavailable');
    }
}
