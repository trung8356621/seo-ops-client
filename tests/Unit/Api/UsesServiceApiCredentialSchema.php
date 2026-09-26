<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait UsesServiceApiCredentialSchema
{
    protected function bootServiceApiCredentialSchema(): void
    {
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
            $table->index('key_prefix');
            $table->index('revoked_at');
            $table->index('expires_at');
        });
    }
}
