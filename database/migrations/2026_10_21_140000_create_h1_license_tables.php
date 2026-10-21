<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H1 installation identity + entitlement ledger.
 *
 * Uses string IDs (ins_*, cred_*, cmd_*) — not the UUID columns on legacy
 * client_control_state / client_control_commands.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('h1_installations')) {
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
        }

        if (! Schema::hasTable('h1_credentials')) {
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

                $table->index(['installation_id', 'status']);
                $table->unique(['installation_id', 'version']);
            });
        }

        if (! Schema::hasTable('h1_command_receipts')) {
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
                $table->index(['installation_id', 'rotation_id']);
            });
        }

        if (Schema::hasTable('sites') && ! Schema::hasColumn('sites', 'h1_is_anchor')) {
            Schema::table('sites', function (Blueprint $table): void {
                $table->boolean('h1_is_anchor')->default(false)->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sites') && Schema::hasColumn('sites', 'h1_is_anchor')) {
            Schema::table('sites', function (Blueprint $table): void {
                $table->dropColumn('h1_is_anchor');
            });
        }

        Schema::dropIfExists('h1_command_receipts');
        Schema::dropIfExists('h1_credentials');
        Schema::dropIfExists('h1_installations');
    }
};
