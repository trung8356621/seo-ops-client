<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Control\Commands\Handlers\ServicesApplyHandler;
use App\Models\ClientControlState;
use App\Models\Service;
use App\Services\AddonManager;
use Illuminate\Console\Command;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;
use Omnichannel\Addons\Seeding\Support\SeedingServiceResolver;
use Illuminate\Support\Facades\Schema;

/**
 * Local/dev bootstrap that mimics ops-server `services.apply` for one slug.
 * Replace-safe: keeps all currently active services and adds/activates the target.
 */
final class SimulateServiceCommand extends Command
{
    protected $signature = 'service:simulate
        {slug=seeding : Service slug to activate}
        {--force : Allow outside local/testing when SERVICE_SIMULATE_FORCE=1}';

    protected $description = 'Local-only: simulate ops-server services.apply for a catalog slug (replace-safe)';

    public function handle(ServicesApplyHandler $handler): int
    {
        if (! $this->environmentAllowed()) {
            $this->error('service:simulate is refused outside local/testing (pass --force with SERVICE_SIMULATE_FORCE=1).');

            return self::FAILURE;
        }

        if (! Schema::hasTable('services')) {
            $this->error('services table missing.');

            return self::FAILURE;
        }

        $slug = trim((string) $this->argument('slug'));
        if ($slug === '') {
            $this->error('slug required');

            return self::FAILURE;
        }

        AddonManager::discover();

        if ($slug === SeedingServiceResolver::SLUG && class_exists(SeedingServiceResolver::class)) {
            app(SeedingServiceResolver::class)->ensureCatalogRow();
        }

        $target = Service::query()->where('slug', $slug)->first();
        if (! $target instanceof Service) {
            $this->error("Unknown service slug [{$slug}] — run AddonManager discover / ensure catalog first.");

            return self::FAILURE;
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
            $target->save();
        }

        $wanted = [];
        foreach (Service::query()->where('is_active', true)->orderBy('id')->get() as $row) {
            $entry = [
                'slug' => (string) $row->slug,
                'config' => is_array($row->config) ? $row->config : [],
            ];
            if (filled($row->service_key)) {
                $entry['service_key'] = (string) $row->service_key;
            }
            $wanted[(string) $row->slug] = $entry;
        }

        $fresh = $target->fresh();
        $targetEntry = [
            'slug' => $slug,
            'config' => is_array($fresh?->config) ? $fresh->config : [],
            'service_key' => filled($fresh?->service_key)
                ? (string) $fresh->service_key
                : ('local-fixture-'.bin2hex(random_bytes(20))),
        ];
        $wanted[$slug] = $targetEntry;

        $revision = (int) (ClientControlState::query()->orderBy('id')->value('services_revision') ?? 0) + 1;

        $result = $handler->handle([
            'mode' => 'replace',
            'revision' => $revision,
            'active_services' => array_values($wanted),
        ]);

        if ($result->error !== null) {
            $this->error('services.apply failed: '.$result->error);

            return self::FAILURE;
        }

        $row = Service::query()->where('slug', $slug)->first();
        $this->info("Simulated services.apply for [{$slug}]");
        $this->line('  is_active: '.((bool) $row?->is_active ? '1' : '0'));
        $this->line('  db_connection: '.((string) ($row?->db_connection ?? '')));
        $this->line('  key_provisioned: '.($row?->hasServiceKey() ? 'yes' : 'no'));
        $this->line('  activated: '.implode(', ', $result->result['activated'] ?? []));
        $this->line('  deactivated: '.implode(', ', $result->result['deactivated'] ?? []) ?: '(none)');

        return self::SUCCESS;
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
