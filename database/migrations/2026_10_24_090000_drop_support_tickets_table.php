<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Retire legacy local Support Tickets.
 *
 * Pre-migration backup (this environment):
 * storage/app/backups/support-tickets-removal-2026-10-24/
 *
 * Attachment policy:
 * - Delete only public-disk files under uploads/support-tickets/
 * - Do NOT delete paths outside that prefix (e.g. uploads/team-chat/ may be shared)
 */
return new class extends Migration
{
    public function up(): void
    {
        $disk = Storage::disk('public');
        if ($disk->exists('uploads/support-tickets')) {
            $disk->deleteDirectory('uploads/support-tickets');
        }

        Schema::dropIfExists('support_tickets');
    }

    public function down(): void
    {
        if (Schema::hasTable('support_tickets')) {
            return;
        }

        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('connection_hash', 64)->nullable();
            $table->string('title');
            $table->text('body');
            $table->string('status', 32)->default('queued');
            $table->string('remote_ticket_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }
};
