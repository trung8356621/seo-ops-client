<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('h1_client_notifications')) {
            return;
        }

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
            /** When false, kept for history but not shown as an active warning. */
            $table->boolean('display_active')->default(true);
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['installation_id', 'display_active'], 'h1_client_ntf_inst_active_idx');
            $table->index(['installation_id', 'service_key'], 'h1_client_ntf_inst_svc_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('h1_client_notifications');
    }
};
