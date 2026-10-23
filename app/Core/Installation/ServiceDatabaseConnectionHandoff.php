<?php

declare(strict_types=1);

namespace App\Core\Installation;

use App\Models\Service;
use App\Services\ServiceDatabaseConnectionResolver;
use App\Services\ServiceDatabasePasswordIntent;
use App\Services\ServiceIdentity;
use Illuminate\Support\Facades\Schema;

/**
 * Setup → canonical ServiceDatabaseConnection handoff.
 *
 * DatabaseConfigurator writes .env / runtime config for migrations.
 * Admin Services health reads only service_database_connections.
 * This class copies installer credentials into the canonical table once
 * Service catalog rows exist — never overwrites an existing connection row.
 */
final class ServiceDatabaseConnectionHandoff
{
    public function __construct(
        private readonly ServiceDatabaseConnectionResolver $resolver,
    ) {}

    /**
     * Idempotent: create missing connections from configured Laravel connections
     * (populated from .env / DatabaseConfigurator). Skip when a row already exists.
     *
     * @return array{
     *     created: list<string>,
     *     skipped: list<string>,
     *     missing_service: list<string>,
     *     incomplete_config: list<string>
     * }
     */
    public function ensureFromConfiguredConnections(): array
    {
        $report = [
            'created' => [],
            'skipped' => [],
            'missing_service' => [],
            'incomplete_config' => [],
        ];

        if (! Schema::hasTable('services') || ! Schema::hasTable('service_database_connections')) {
            return $report;
        }

        foreach (ServiceIdentity::knownPublicSlugs() as $publicSlug) {
            $service = ServiceIdentity::findService($publicSlug);
            if (! $service instanceof Service) {
                $report['missing_service'][] = $publicSlug;

                continue;
            }

            if ($this->resolver->connectionForService($service) !== null) {
                $report['skipped'][] = $publicSlug;

                continue;
            }

            $logical = ServiceIdentity::defaultLogicalConnection($publicSlug);
            $cfg = config('database.connections.'.$logical);
            if (! is_array($cfg)) {
                $report['incomplete_config'][] = $publicSlug;

                continue;
            }

            $database = trim((string) ($cfg['database'] ?? ''));
            $username = trim((string) ($cfg['username'] ?? ''));
            if ($database === '' || $username === '') {
                $report['incomplete_config'][] = $publicSlug;

                continue;
            }

            if (blank($service->db_connection) || $service->db_connection === 'mysql') {
                $service->forceFill(['db_connection' => $logical])->save();
            }

            $plain = (string) ($cfg['password'] ?? '');
            $this->resolver->upsert(
                $service,
                [
                    'type' => 'manual',
                    'driver' => (string) ($cfg['driver'] ?? 'mysql'),
                    'host' => (string) ($cfg['host'] ?? '127.0.0.1'),
                    'port' => (string) ($cfg['port'] ?? '3306'),
                    'database' => $database,
                    'username' => $username,
                ],
                [
                    'action' => ServiceDatabasePasswordIntent::ACTION_SET,
                    'plain' => $plain === '' ? null : $plain,
                ],
            );

            $report['created'][] = $publicSlug;
        }

        return $report;
    }
}
