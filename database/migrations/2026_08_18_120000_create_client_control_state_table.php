<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_control_state', function (Blueprint $table): void {
            $table->id();
            $table->uuid('installation_id')->nullable()->unique();
            $table->string('control_server_url')->nullable();
            $table->text('installation_secret')->nullable();
            $table->string('status', 32)->default('unregistered');
            $table->unsignedBigInteger('services_revision')->nullable();
            $table->string('client_version', 64)->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('last_command_at')->nullable();
            $table->uuid('last_command_id')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_control_state');
    }
};
