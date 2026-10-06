<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ImportLab\ImportLabResetWorkflow;
use App\Services\ImportLab\ImportLabSafetyGuard;
use Illuminate\Console\Command;
use Illuminate\Database\ConfigurationUrlParser;
use RuntimeException;
use Throwable;

final class ResetClientImportLabCommand extends Command
{
    protected $signature = 'client-test:reset {--yes : Skip confirmation after safety validation}';

    protected $description = 'Destructively recreate the dedicated local import-test databases';

    public function __construct(
        private readonly ImportLabSafetyGuard $guard,
        private readonly ImportLabResetWorkflow $workflow,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $connections = $this->connections();
        $databases = array_map(static fn (array $config): string => trim((string) ($config['database'] ?? '')), $connections);

        try {
            $this->guard->assertSafe((string) app()->environment(), base_path(), $databases);
        } catch (RuntimeException $e) {
            $this->error('ABORT: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('Core DB: '.$databases['core']);
        $this->line('SEO DB: '.$databases['seo']);
        $this->line('Seeding DB: '.$databases['seeding']);

        if (! $this->option('yes') && ! $this->confirm('Drop and recreate all three import-lab databases?', false)) {
            $this->warn('ABORT: confirmation declined.');

            return self::FAILURE;
        }

        try {
            $result = $this->workflow->run($connections);
        } catch (Throwable $e) {
            $this->error('Reset failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Core DB recreated: YES');
        $this->line('SEO DB recreated: YES');
        $this->line('Seeding DB recreated: YES');
        $this->newLine();
        $this->line('Migrations: PASS');
        $this->serviceReport('SEO', $result['services']['seo']);
        $this->serviceReport('Seeding', $result['services']['seeding']);
        $this->newLine();
        $this->line('SEO portable target:');
        $this->line('  schema ready: YES');
        $this->line('  business data empty: YES');
        $this->newLine();
        $this->info('Ready for import: YES');

        return self::SUCCESS;
    }

    /** @return array<string, array<string, mixed>> */
    private function connections(): array
    {
        $core = (string) config('database.core_connection', 'mysql');

        return [
            'core' => $this->parseConnection((array) config('database.connections.'.$core, [])),
            'seo' => $this->parseConnection((array) config('database.connections.omi_seo_ai', [])),
            'seeding' => $this->parseConnection((array) config('database.connections.omi_seeding', [])),
        ];
    }

    /**
     * Resolve DB_URL/SEO_DB_URL/SEEDING_DB_URL exactly as Laravel does.
     *
     * @param  array<string, mixed>  $connection
     * @return array<string, mixed>
     */
    private function parseConnection(array $connection): array
    {
        return (new ConfigurationUrlParser)->parseConfiguration($connection);
    }

    /** @param array<string, mixed> $service */
    private function serviceReport(string $label, array $service): void
    {
        $this->newLine();
        $this->line($label.' service:');
        $this->line('  active: YES');
        $this->line('  database: '.(string) $service['database']);
        $this->line('  key: present');
    }
}
