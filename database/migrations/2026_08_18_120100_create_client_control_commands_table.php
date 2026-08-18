<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_control_commands', function (Blueprint $table): void {
            $table->id();
            $table->uuid('command_id')->unique();
            $table->string('command', 64);
            $table->string('payload_hash', 64);
            $table->string('status', 32)->default('received');
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_control_commands');
    }
};
