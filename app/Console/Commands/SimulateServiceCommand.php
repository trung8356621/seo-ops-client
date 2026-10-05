<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Service;
use App\Services\AddonManager;
use Illuminate\Console\Command;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;
use Omnichannel\Addons\Seeding\Support\SeedingServiceResolver;
use Illuminate\Support\Facades\Schema;

/**
 * Local/dev bootstrap to activate a service for one slug.
 */
final class SimulateServiceCommand extends Command
{
    protected $signature = 'service:simulate
        {slug? : Service slug to activate}
        {--all : Simulate activation for all discovered addons}
        {--force : Allow outside local/testing when SERVICE_SIMULATE_FORCE=1}';

    protected $description = 'Local-only: simulate service activation for catalog slugs';

    public function handle(): int
    {
        if (! $this->environmentAllowed()) {
            $this->error('service:simulate is refused outside local/testing (pass --force with SERVICE_SIMULATE_FORCE=1).');

            return self::FAILURE;
        }

        if (! Schema::hasTable('services')) {
            $this->error('services table missing.');

            return self::FAILURE;
        }

        if ($this->option('all')) {
            $discovered = AddonManager::discover();
            if (empty($discovered)) {
                $this->warn('No addons discovered.');

                return self::SUCCESS;
            }

            $rows = [];
            foreach ($discovered as $slug) {
                $row = $this->activateSlug($slug);
                $rows[] = [
                    'slug' => $slug,
                    'is_active' => (bool) $row?->is_active ? '1' : '0',
                    'db_connection' => (string) ($row?->db_connection ?? ''),
                    'key_provisioned' => $row?->hasServiceKey() ? 'yes' : 'no',
                ];
            }

            $this->table(['Slug', 'Active', 'DB Connection', 'Key Provisioned'], $rows);
            $this->info('Simulated service activation for all discovered addons ('.count($rows).' total).');

            return self::SUCCESS;
        }

        $slug = trim((string) ($this->argument('slug') ?? 'seeding'));
        if ($slug === '') {
            $slug = 'seeding';
        }

        AddonManager::discover();

        $row = $this->activateSlug($slug);
        if (! $row instanceof Service) {
            $this->error("Unknown service slug [{$slug}] — run AddonManager discover / ensure catalog first.");

            return self::FAILURE;
        }

        $this->info("Simulated service activation for [{$slug}]");
        $this->line('  is_active: '.((bool) $row->is_active ? '1' : '0'));
        $this->line('  db_connection: '.((string) ($row->db_connection ?? '')));
        $this->line('  key_provisioned: '.($row->hasServiceKey() ? 'yes' : 'no'));
        $this->line('  activated: '.$slug);

        return self::SUCCESS;
    }

    private function activateSlug(string $slug): ?Service
    {
        if ($slug === SeedingServiceResolver::SLUG && class_exists(SeedingServiceResolver::class)) {
            app(SeedingServiceResolver::class)->ensureCatalogRow();
        }

        $target = Service::query()->where('slug', $slug)->first();
        if (! $target instanceof Service) {
            return null;
        }

        if ($slug === SeedingServiceResolver::SLUG) {
            $target->name = $target->name ?: 'Seeding';
            $target->db_connection = SeedingServiceConfig::CONNECTION;
            $config = is_array($target->config) ? $target->config : [];
            $config['enabled'] = true;
            $config['version'] = $config['version'] ?? '0.2.0';
            $config['database'] = [
                'connection' => SeedingServiceConfig::CONNECTION,
                'database' => 'omi_seeding',
            ];
            $target->config = $config;
        }

        $target->is_active = true;
        $hasServiceKeyColumn = Schema::hasColumn('services', 'service_key');
        if ($hasServiceKeyColumn && ! filled($target->service_key)) {
            $target->service_key = 'local-fixture-'.bin2hex(random_bytes(20));
        }
        $target->save();

        return $target->fresh();
    }

    private function environmentAllowed(): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return true;
        }

        return (bool) $this->option('force')
            && filter_var(env('SERVICE_SIMULATE_FORCE', false), FILTER_VALIDATE_BOOL);
    }
}
