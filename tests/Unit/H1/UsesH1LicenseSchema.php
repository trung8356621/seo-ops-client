<?php

declare(strict_types=1);

namespace Tests\Unit\H1;

use App\Services\Gateway\EntitlementAppliedHook;
use App\Services\Gateway\EntitlementPresentationStore;
use App\Services\Gateway\ServiceGatewayProvisioner;
use App\Services\Gateway\VerifiedEntitlementSnapshot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Unit\Api\UsesServiceApiCredentialSchema;

trait UsesH1LicenseSchema
{
    use UsesServiceApiCredentialSchema;

    protected function bootH1LicenseSchema(): void
    {
        $this->bootServiceApiCredentialSchema();

        // Avoid Service Gateway provisioner cost/lock contention in isolated H1 tests.
        $store = app(EntitlementPresentationStore::class);
        $provisioner = app(ServiceGatewayProvisioner::class);
        $this->app->instance(EntitlementAppliedHook::class, new class($store, $provisioner) extends EntitlementAppliedHook
        {
            public function __construct(
                private readonly EntitlementPresentationStore $store,
                ServiceGatewayProvisioner $provisioner,
            ) {
                parent::__construct($store, $provisioner);
            }

            public function afterVerifiedEntitlementApplied(VerifiedEntitlementSnapshot $snapshot): array
            {
                $this->store->save($snapshot);

                return [];
            }
        });

        Schema::dropIfExists('h1_command_receipts');
        Schema::dropIfExists('h1_credentials');
        Schema::dropIfExists('h1_installations');
        Schema::dropIfExists('sites');

        Schema::create('h1_installations', function (Blueprint $table): void {
            $table->id();
            $table->string('installation_id', 128)->unique();
            $table->string('product', 64)->default('seo-ops');
            $table->string('control_server_url')->nullable();
            $table->string('status', 32)->default('enrolled');
            $table->string('cutover_mode', 32)->default('pre_enrollment');
            $table->string('anchor_identity', 255)->nullable();
            $table->unsignedBigInteger('anchor_site_id')->nullable();
            $table->string('client_version', 64)->nullable();
            $table->unsignedBigInteger('entitlement_revision')->default(0);
            $table->unsignedInteger('credential_version')->default(0);
            $table->string('last_command_id', 128)->nullable();
            $table->json('verified_snapshot')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->json('pending_acks')->nullable();
            $table->json('rotation_state')->nullable();
            $table->timestamps();
        });

        Schema::create('h1_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('installation_id', 128);
            $table->string('credential_id', 128)->unique();
            $table->unsignedInteger('version');
            $table->text('secret_encrypted');
            $table->string('status', 32)->default('active');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->unique(['installation_id', 'version']);
        });

        Schema::create('h1_command_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('installation_id', 128);
            $table->string('command_id', 128);
            $table->string('message_type', 64);
            $table->string('payload_hash', 128);
            $table->string('result', 32);
            $table->string('reason', 128)->nullable();
            $table->boolean('retryable')->default(false);
            $table->boolean('quota_overflow_reset')->default(false);
            $table->string('rotation_id', 128)->nullable();
            $table->json('ack_json');
            $table->timestamp('processed_at');
            $table->timestamps();
            $table->unique(['installation_id', 'command_id']);
        });

        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('domain')->unique();
            $table->string('status', 32)->default('inactive');
            $table->boolean('ssl')->default(true);
            $table->boolean('h1_is_anchor')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        config([
            'h1-license.enforcement_mode' => 'enforcing',
            'h1-license.require_https' => false,
            'h1-license.enrollment.transport' => 'fixture',
            'h1-license.schedule_enabled' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function sampleServices(int $seoQuota = 30, bool $seedingEnabled = true, ?string $seoUntil = '2029-10-21T00:00:00Z', ?string $seedingUntil = '2028-10-21T00:00:00Z'): array
    {
        return [
            [
                'key' => 'agent',
                'scope' => 'global',
                'license_model' => null,
                'enabled' => true,
                'quota' => null,
            ],
            [
                'key' => 'seo',
                'scope' => 'licensed',
                'license_model' => 'domain_quota',
                'enabled' => true,
                'quota' => $seoQuota,
                'valid_until' => $seoUntil,
                'grace_until' => null,
                'revoked_at' => null,
            ],
            [
                'key' => 'seeding',
                'scope' => 'licensed',
                'license_model' => 'flat',
                'enabled' => $seedingEnabled,
                'quota' => null,
                'valid_until' => $seedingUntil,
                'grace_until' => null,
                'revoked_at' => null,
            ],
        ];
    }
}
