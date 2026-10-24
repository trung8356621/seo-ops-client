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

    public function test_idempotent_record_and_resume_supersedes_suspend(): void
    {
        $store = app(ClientNotificationStore::class);
        $payload = [
            'id' => 'cmd_suspend_1',
            'action' => 'suspend',
            'service_key' => 'seo',
            'message' => 'Tạm ngưng bảo trì',
            'status_before' => 'active',
            'status_after' => 'suspended',
            'entitlement_revision' => 10,
            'issued_at' => '2026-10-24T09:00:00Z',
        ];
        $store->recordFromCommand('ins_01', 'cmd_suspend_1', $payload);
        $store->recordFromCommand('ins_01', 'cmd_suspend_1', $payload);
        self::assertSame(1, H1ClientNotification::query()->count());
        self::assertTrue((bool) H1ClientNotification::query()->value('display_active'));

        $store->recordFromCommand('ins_01', 'cmd_resume_1', [
            'id' => 'cmd_resume_1',
            'action' => 'resume',
            'service_key' => 'seo',
            'message' => 'Đã khôi phục',
            'status_before' => 'suspended',
            'status_after' => 'active',
            'entitlement_revision' => 11,
            'issued_at' => '2026-10-24T10:00:00Z',
        ]);

        $suspend = H1ClientNotification::query()->where('notification_id', 'cmd_suspend_1')->first();
        self::assertNotNull($suspend);
        self::assertFalse((bool) $suspend->display_active);

        $active = $store->activeForInstallation('ins_01');
        self::assertCount(0, $active);

        $history = $store->recentHistory('ins_01');
        self::assertGreaterThanOrEqual(2, count($history));
        self::assertSame('Tạm ngưng bảo trì', $history[1]['message'] ?? $history[0]['message']);
    }
}
