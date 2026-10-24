<?php

declare(strict_types=1);

namespace Tests\Unit\H1;

use App\Models\H1ClientNotification;
use App\Services\H1\Notifications\ClientNotificationStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ClientNotificationStoreTest extends TestCase
{
    use UsesH1LicenseSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootH1LicenseSchema();
        Schema::dropIfExists('h1_client_notifications');
        Schema::create('h1_client_notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('notification_id', 128)->unique();
            $table->string('installation_id', 128);
            $table->string('command_id', 128)->nullable();
            $table->string('action', 32);
            $table->string('service_key', 64)->nullable();
            $table->text('message');
            $table->string('status_before', 32)->nullable();
            $table->string('status_after', 32)->nullable();
            $table->unsignedBigInteger('entitlement_revision')->default(0);
            $table->timestamp('issued_at')->nullable();
            $table->boolean('display_active')->default(true);
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_idempotent_and_resume_replaces_suspend_as_latest(): void
    {
        $store = app(ClientNotificationStore::class);
        $suspend = [
            'id' => 'cmd_suspend_1',
            'action' => 'suspend',
            'service_key' => 'seo',
            'message' => 'Tạm ngưng bảo trì',
            'status_before' => 'active',
            'status_after' => 'suspended',
            'entitlement_revision' => 10,
            'issued_at' => '2026-10-24T09:00:00Z',
        ];
        $store->recordFromCommand('ins_01', 'cmd_suspend_1', $suspend);
        $store->recordFromCommand('ins_01', 'cmd_suspend_1', $suspend);
        self::assertSame(1, H1ClientNotification::query()->count());

        $store->recordFromCommand('ins_01', 'cmd_resume_1', [
            'id' => 'cmd_resume_1',
            'action' => 'resume',
            'service_key' => 'seo',
            'message' => 'Đã khôi phục sau bảo trì',
            'status_before' => 'suspended',
            'status_after' => 'active',
            'entitlement_revision' => 11,
            'issued_at' => '2026-10-24T10:00:00Z',
        ]);

        self::assertSame(2, H1ClientNotification::query()->count());
        $latest = $store->latestByService('ins_01');
        self::assertArrayHasKey('seo', $latest);
        self::assertSame('resume', $latest['seo']['action']);
        self::assertSame('Đã khôi phục sau bảo trì', $latest['seo']['message']);
        self::assertFalse((bool) H1ClientNotification::query()->where('notification_id', 'cmd_suspend_1')->value('display_active'));
        self::assertTrue((bool) H1ClientNotification::query()->where('notification_id', 'cmd_resume_1')->value('display_active'));
    }

    public function test_notifications_do_not_cross_services(): void
    {
        $store = app(ClientNotificationStore::class);
        $store->recordFromCommand('ins_01', 'cmd_s', [
            'id' => 'cmd_s',
            'action' => 'suspend',
            'service_key' => 'seo',
            'message' => 'SEO pause',
            'entitlement_revision' => 1,
            'issued_at' => '2026-10-24T09:00:00Z',
        ]);
        $store->recordFromCommand('ins_01', 'cmd_k', [
            'id' => 'cmd_k',
            'action' => 'revoke',
            'service_key' => 'seeding',
            'message' => 'Seeding revoke',
            'entitlement_revision' => 2,
            'issued_at' => '2026-10-24T09:01:00Z',
        ]);

        $latest = $store->latestByService('ins_01');
        self::assertSame('SEO pause', $latest['seo']['message']);
        self::assertSame('Seeding revoke', $latest['seeding']['message']);
    }
}
