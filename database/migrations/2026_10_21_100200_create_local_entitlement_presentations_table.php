<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Safe, non-secret license presentation for Gateway Control Gate UI.
 * Written only by verified entitlement apply hook (future H1) or test adapter.
 * Never stores machine credentials / installation secrets.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('local_entitlement_presentations')) {
            return;
        }

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

    public function down(): void
    {
        Schema::dropIfExists('local_entitlement_presentations');
    }
};
