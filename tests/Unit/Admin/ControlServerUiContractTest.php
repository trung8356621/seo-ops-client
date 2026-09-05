<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use App\Enums\ClientControlStatus;
use App\Filament\Pages\ControlServer;
use App\Filament\Pages\ServiceConfigure;
use App\Models\ClientControlState;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ControlServerUiContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('client_control_state')) {
            Schema::create('client_control_state', function (Blueprint $table): void {
                $table->id();
                $table->uuid('installation_id')->nullable()->unique();
                $table->string('control_server_url')->nullable();
                $table->text('installation_secret')->nullable();
                $table->string('status', 32)->default('unregistered');
                $table->unsignedBigInteger('services_revision')->nullable();
                $table->string('client_version', 64)->nullable();
                $table->timestamp('locked_at')->nullable();
                $table->timestamp('last_command_at')->nullable();
                $table->uuid('last_command_id')->nullable();
                $table->timestamp('connected_at')->nullable();
                $table->timestamps();
            });
        } else {
            ClientControlState::query()->delete();
        }
    }

    public function test_unregistered_shows_connect_form_without_empty_status_dump(): void
    {
        $owner = new User(['role' => User::ROLE_OWNER, 'status' => User::STATUS_NORMAL]);
        $this->actingAs($owner);

        $page = app(ControlServer::class);
        $data = $page->getInstallationViewData();

        self::assertTrue($data['can_enroll']);
        self::assertFalse($data['is_connected']);
        self::assertFalse($data['show_status_panel']);
        self::assertSame('unregistered', $data['status']);

        $view = (string) file_get_contents(resource_path('views/filament/pages/control-server.blade.php'));
        self::assertStringContainsString('unregistered_heading', $view);
        self::assertStringContainsString('wire:submit="connect"', $view);
        self::assertStringNotContainsString('Simulate', $view);
        self::assertStringNotContainsString('Fake enrollment', $view);
    }

    public function test_active_hides_api_key_and_separates_lock_semantics(): void
    {
        ClientControlState::query()->create([
            'installation_id' => '11111111-1111-4111-8111-111111111111',
            'control_server_url' => 'https://ops.example.test',
            'installation_secret' => 'secret-value',
            'status' => ClientControlStatus::Active,
            'client_version' => '0.0.9',
            'services_revision' => 7,
            'connected_at' => now(),
        ]);

        $owner = new User(['role' => User::ROLE_OWNER, 'status' => User::STATUS_NORMAL]);
        $this->actingAs($owner);

        $page = app(ControlServer::class);
        $data = $page->getInstallationViewData();

        self::assertFalse($data['can_enroll']);
        self::assertTrue($data['is_connected']);
        self::assertTrue($data['show_status_panel']);
        self::assertSame('Unlocked', $data['control_lock_label']);
        self::assertSame('Active', $data['status_label']);
        self::assertSame(7, $data['services_revision']);
        self::assertArrayNotHasKey('installation_secret', $data);
        self::assertArrayNotHasKey('api_key', $data);

        $raw = DB::table('client_control_state')->value('installation_secret');
        self::assertNotSame('secret-value', $raw);
    }

    public function test_locked_and_revoked_labels(): void
    {
        ClientControlState::query()->create([
            'installation_id' => '11111111-1111-4111-8111-111111111111',
            'control_server_url' => 'https://ops.example.test',
            'installation_secret' => 'x',
            'status' => ClientControlStatus::Locked,
            'locked_at' => now(),
            'connected_at' => now(),
        ]);

        $owner = new User(['role' => User::ROLE_OWNER, 'status' => User::STATUS_NORMAL]);
        $this->actingAs($owner);
        $page = app(ControlServer::class);
        $locked = $page->getInstallationViewData();
        self::assertTrue($locked['is_locked']);
        self::assertSame('Locked', $locked['control_lock_label']);
        self::assertFalse($locked['can_enroll']);

        ClientControlState::query()->delete();
        ClientControlState::query()->create([
            'installation_id' => '11111111-1111-4111-8111-111111111112',
            'control_server_url' => 'https://ops.example.test',
            'installation_secret' => 'y',
            'status' => ClientControlStatus::Revoked,
            'connected_at' => now(),
        ]);

        $revoked = app(ControlServer::class)->getInstallationViewData();
        self::assertTrue($revoked['is_revoked']);
        self::assertTrue($revoked['can_enroll']);
        self::assertSame('Revoked', $revoked['status_label']);
    }

    public function test_service_configure_has_no_password_required_gate(): void
    {
        $src = (string) file_get_contents(app_path('Filament/Pages/ServiceConfigure.php'));
        self::assertStringNotContainsString('Mật khẩu bắt buộc khi tạo kết nối', $src);
        self::assertStringContainsString('testDraftAttributes', $src);
        self::assertStringContainsString('ServiceDatabasePasswordIntent', $src);
        self::assertStringNotContainsString('env fallback', strtolower($src));
    }
}
