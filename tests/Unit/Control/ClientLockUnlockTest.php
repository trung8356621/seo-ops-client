<?php

declare(strict_types=1);

namespace Tests\Unit\Control;

use App\Enums\ClientControlStatus;
use App\Models\ClientControlState;
use Tests\TestCase;

final class ClientLockUnlockTest extends TestCase
{
    use InteractsWithClientControl;
    use UsesClientControlSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootClientControlSchema();
    }

    public function test_lock_persists_and_unlock_clears(): void
    {
        $this->seedEnrolledState();

        $lock = $this->postControlCommand('client.lock');
        $lock->assertOk();
        $lock->assertJsonPath('result.status', ClientControlStatus::Locked->value);

        $state = ClientControlState::query()->orderBy('id')->firstOrFail();
        $this->assertSame(ClientControlStatus::Locked, $state->status);
        $this->assertNotNull($state->locked_at);

        $unlock = $this->postControlCommand('client.unlock');
        $unlock->assertOk();
        $unlock->assertJsonPath('result.status', ClientControlStatus::Active->value);

        $state->refresh();
        $this->assertSame(ClientControlStatus::Active, $state->status);
        $this->assertNull($state->locked_at);
    }

    public function test_control_endpoint_remains_reachable_while_locked(): void
    {
        $this->seedEnrolledState(ClientControlStatus::Locked);

        $response = $this->postControlCommand('client.unlock');

        $response->assertOk();
        $this->assertSame(ClientControlStatus::Active, ClientControlState::query()->orderBy('id')->firstOrFail()->status);
    }

    public function test_business_route_is_blocked_while_locked(): void
    {
        $this->seedEnrolledState(ClientControlStatus::Locked);

        $this->assertTrue(app(\App\Control\ClientLockGuard::class)->isLocked());

        $response = $this->get('/');

        $response->assertStatus(423);
        $response->assertSee('Client is locked by the control server.', false);
    }

    public function test_control_login_and_health_remain_reachable_while_locked(): void
    {
        $this->seedEnrolledState(ClientControlStatus::Locked);

        $this->get('/up')->assertOk();
        $this->get('/admin/login')->assertOk();
        $this->get('/client-locked')->assertOk();
    }
}
