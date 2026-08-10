<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drop zero-consumer core table frontend_projects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('frontend_projects');
    }

    public function down(): void
    {
        // Irreversible — restore from backup.
    }
};
