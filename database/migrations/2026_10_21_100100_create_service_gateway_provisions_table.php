<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-Service Service Gate provisioning state (no secrets).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('service_gateway_provisions')) {
            return;
        }

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

            $table->index('public_slug');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_gateway_provisions');
    }
};
