<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait UsesServiceApiCredentialSchema
{
    protected function bootServiceApiCredentialSchema(): void
    {
        Schema::dropIfExists('service_gateway_provisions');
        Schema::dropIfExists('local_entitlement_presentations');
        Schema::dropIfExists('service_api_credentials');
        Schema::dropIfExists('services');

        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('addon_namespace')->nullable();
            $table->string('db_connection')->default('mysql');
            $table->boolean('is_active')->default(true);
            $table->json('config')->nullable();
            $table->text('service_key')->nullable();
            $table->timestamps();
        });

        Schema::create('service_api_credentials', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('service_id');
            $table->string('kind', 16)->default('user');
            $table->string('name', 120);
            $table->string('key_prefix', 64);
            $table->string('key_hash', 128);
            $table->json('scopes')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('service_id');
            $table->index(['service_id', 'kind']);
            $table->index('key_prefix');
            $table->index('revoked_at');
            $table->index('expires_at');
        });

        Schema::create('service_gateway_provisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('service_id')->unique();
            $table->string('public_slug', 64);
            $table->string('status', 32)->default('pending');
            $table->boolean('entitled')->default(false);
            $table->boolean('execution_allowed')->default(false);
            $table->boolean('requires_managed_credential')->default(false);
            $table->unsignedBigInteger('managed_credential_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_provisioned_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('local_entitlement_presentations', function (Blueprint $table): void {
            $table->id();
            $table->boolean('connected')->default(false);
            $table->string('license_status', 32)->default('disconnected');
            $table->string('product_name', 120)->nullable();
            $table->string('plan_name', 120)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('entitled_services')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }
}
