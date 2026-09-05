<?php

declare(strict_types=1);

use App\Models\Service;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Copy legacy SEO / Seeding connection rows into service_database_connections.
 * Does NOT drop legacy tables. Skips ambiguous multi-active SEO sets.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('services') || ! Schema::hasTable('service_database_connections')) {
            return;
        }

        $this->migrateSeo();
        $this->migrateSeeding();
        $this->ensureLocalFixtureKeys();
    }

    public function down(): void
    {
        // Data migration — no automatic reverse.
    }

    private function migrateSeo(): void
    {
        if (! Schema::hasTable('seo_database_connections')) {
            return;
        }

        $service = Service::query()
            ->whereIn('slug', ['seo-content-ai', 'seo'])
            ->orderByRaw("CASE WHEN slug = 'seo-content-ai' THEN 0 ELSE 1 END")
            ->first();

        if ($service === null) {
            return;
        }

        if (DB::table('service_database_connections')->where('service_id', $service->id)->exists()) {
            return;
        }

        $actives = DB::table('seo_database_connections')
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($actives->isEmpty()) {
            $actives = DB::table('seo_database_connections')->orderBy('id')->limit(1)->get();
        }

        if ($actives->count() > 1) {
            $preferred = $actives->first(fn ($row): bool => strcasecmp((string) ($row->database ?? ''), 'omi_seo_ai') === 0);
            if ($preferred === null) {
                Log::warning('service_database_connections SEO migrate skipped: multiple active seo_database_connections', [
                    'count' => $actives->count(),
                    'service_id' => $service->id,
                ]);

                return;
            }
            $source = $preferred;
        } else {
            $source = $actives->first();
        }

        if ($source === null) {
            return;
        }

        DB::table('service_database_connections')->insert([
            'service_id' => $service->id,
            'type' => (string) ($source->type ?: 'manual'),
            'driver' => 'mysql',
            'host' => $source->host,
            'port' => $source->port,
            'database' => $source->database,
            'username' => $source->username,
            'password' => $source->password,
            'is_active' => (bool) $source->is_active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (blank($service->db_connection) || $service->db_connection === 'mysql') {
            $service->forceFill(['db_connection' => 'omi_seo_ai'])->saveQuietly();
        }
    }

    private function migrateSeeding(): void
    {
        if (! Schema::hasTable('seeding_database_connections')) {
            return;
        }

        $service = Service::query()->where('slug', 'seeding')->first();
        if ($service === null) {
            return;
        }

        if (DB::table('service_database_connections')->where('service_id', $service->id)->exists()) {
            return;
        }

        $source = DB::table('seeding_database_connections')
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first()
            ?? DB::table('seeding_database_connections')->orderByDesc('id')->first();

        if ($source === null) {
            return;
        }

        DB::table('service_database_connections')->insert([
            'service_id' => $service->id,
            'type' => (string) ($source->type ?: 'manual'),
            'driver' => 'mysql',
            'host' => $source->host,
            'port' => $source->port,
            'database' => $source->database,
            'username' => $source->username,
            'password' => $source->password,
            'is_active' => (bool) $source->is_active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (blank($service->db_connection) || $service->db_connection === 'mysql') {
            $service->forceFill(['db_connection' => 'omi_seeding'])->saveQuietly();
        }
    }

    /**
     * Local/testing fixture: ensure SEO + Seeding rows have a non-empty service_key
     * simulating ops-server provisioning. Never logs the secret.
     */
    private function ensureLocalFixtureKeys(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        if (! Schema::hasColumn('services', 'service_key')) {
            return;
        }

        foreach (['seo-content-ai', 'seo', 'seeding'] as $slug) {
            $service = Service::query()->where('slug', $slug)->first();
            if ($service === null) {
                continue;
            }
            if (filled($service->service_key)) {
                continue;
            }
            $service->forceFill([
                'service_key' => 'local-fixture-'.Str::random(40),
            ])->saveQuietly();
        }
    }
};
