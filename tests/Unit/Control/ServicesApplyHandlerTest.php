<?php

declare(strict_types=1);

namespace Tests\Unit\Control;

use App\Control\Commands\Handlers\ServicesApplyHandler;
use App\Enums\ClientControlStatus;
use App\Models\ClientControlState;
use App\Models\Service;
use Tests\TestCase;

final class ServicesApplyHandlerTest extends TestCase
{
    use InteractsWithClientControl;
    use UsesClientControlSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootClientControlSchema();
        $this->seedEnrolledState();
    }

    public function test_replace_snapshot_uses_slug_not_remote_id(): void
    {
        $keep = $this->makeRuntimeService('seo-content-ai', false, ['old' => 1]);
        $drop = $this->makeRuntimeService('media', true);
        $keep->forceFill(['id' => 1])->save();
        $drop->forceFill(['id' => 99])->save();

        $result = app(ServicesApplyHandler::class)->handle([
            'revision' => 12,
            'mode' => 'replace',
            'active_services' => [
                [
                    'id' => 99,
                    'slug' => 'seo-content-ai',
                    'config' => ['from_server' => true],
                ],
            ],
        ]);

        $this->assertNull($result->error);
        $this->assertTrue((bool) Service::query()->where('slug', 'seo-content-ai')->value('is_active'));
        $this->assertFalse((bool) Service::query()->where('slug', 'media')->value('is_active'));
        $this->assertSame(['from_server' => true], Service::query()->where('slug', 'seo-content-ai')->first()?->config);
        $this->assertSame(12, ClientControlState::query()->orderBy('id')->first()?->services_revision);
    }

    public function test_unknown_slug_does_not_load_arbitrary_code_and_is_reported(): void
    {
        $this->makeRuntimeService('seo-content-ai', true);

        $result = app(ServicesApplyHandler::class)->handle([
            'revision' => 3,
            'mode' => 'replace',
            'active_services' => [
                ['slug' => 'seo-content-ai', 'config' => []],
                ['slug' => 'App\\Evil\\Payload', 'config' => []],
                ['slug' => 'not-installed-addon', 'config' => []],
            ],
        ]);

        $this->assertNull($result->error);
        $this->assertSame(['App\\Evil\\Payload', 'not-installed-addon'], $result->result['unknown_slugs'] ?? null);
        $this->assertFalse(class_exists('App\\Evil\\Payload', false));
        $this->assertNull(Service::query()->where('slug', 'App\\Evil\\Payload')->first());
        $this->assertNull(Service::query()->where('slug', 'not-installed-addon')->first());
        $this->assertTrue((bool) Service::query()->where('slug', 'seo-content-ai')->value('is_active'));
        $this->assertSame(3, ClientControlState::query()->orderBy('id')->first()?->services_revision);
    }

    public function test_failed_apply_does_not_update_revision(): void
    {
        $this->makeRuntimeService('seo-content-ai', true);

        $result = app(ServicesApplyHandler::class)->handle([
            'revision' => 99,
            'mode' => 'merge',
            'active_services' => [
                ['slug' => 'seo-content-ai', 'config' => []],
            ],
        ]);

        $this->assertSame('invalid_mode', $result->error);
        $this->assertNull(ClientControlState::query()->orderBy('id')->first()?->services_revision);
        $this->assertSame(ClientControlStatus::Active, ClientControlState::query()->orderBy('id')->first()?->status);
    }
}
