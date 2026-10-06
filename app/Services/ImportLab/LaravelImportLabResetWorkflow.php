<?php

declare(strict_types=1);

namespace App\Services\ImportLab;

use App\Models\Service;
use App\Services\ClientTransfer\TargetSchemaValidator;
use App\Services\ServiceDatabaseConnectionResolver;
use App\Services\ServiceDatabasePasswordIntent;
use App\Services\ServiceIdentity;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class LaravelImportLabResetWorkflow implements ImportLabResetWorkflow
{
    public function __construct(
        private readonly ImportLabDatabaseAdmin $databaseAdmin,
        private readonly ImportLabRuntime $runtime,
        private readonly ServiceDatabaseConnectionResolver $resolver,
        private readonly TargetSchemaValidator $schemaValidator,
    ) {}

    public function run(array $connections): array
    {
        foreach (['mysql', 'omi_seo_ai', 'omi_seeding'] as $name) {
            DB::purge($name);
        }
        $this->databaseAdmin->recreate($connections);

        try {
            $this->runtime->clearConfig();
            $this->runtime->migrate();
        } catch (RuntimeException $e) {
            throw new RuntimeException($this->migrationFailure($connections, $e->getMessage()), 0, $e);
        }
        $this->runtime->bootstrapServices();

        $this->configureService(ServiceIdentity::PUBLIC_SEO, 'omi_seo_ai', $connections['seo']);
        $this->configureService(ServiceIdentity::PUBLIC_SEEDING, 'omi_seeding', $connections['seeding']);

        return $this->validate($connections);
    }

    /** @param array<string, mixed> $connection */
    private function configureService(string $slug, string $logical, array $connection): void
    {
        $service = $this->resolver->findService($slug);
        if (! $service instanceof Service) {
            throw new RuntimeException("Service [{$slug}] was not created during bootstrap.");
        }

        $service->forceFill(['db_connection' => $logical, 'is_active' => true])->save();
        $password = (string) ($connection['password'] ?? '');
        $this->resolver->upsert($service, [
            'type' => 'manual',
            'driver' => 'mysql',
            'host' => $connection['host'] ?? '127.0.0.1',
            'port' => $connection['port'] ?? '3306',
            'database' => $connection['database'],
            'username' => $connection['username'] ?? 'root',
            'is_active' => true,
        ], [
            'action' => $password === '' ? ServiceDatabasePasswordIntent::ACTION_CLEAR : ServiceDatabasePasswordIntent::ACTION_SET,
            'plain' => $password === '' ? null : $password,
        ]);
        $this->resolver->bootstrap($slug, forceReconnect: true);
    }

    /** @return array<string, mixed> */
    private function validate(array $connections): array
    {
        $services = [];
        foreach ([ServiceIdentity::PUBLIC_SEO => 'seo', ServiceIdentity::PUBLIC_SEEDING => 'seeding'] as $slug => $key) {
            $service = $this->resolver->findService($slug);
            $row = $service instanceof Service ? $this->resolver->connectionForService($service) : null;
            $services[$key] = [
                'active' => (bool) $service?->is_active,
                'database' => (string) ($row?->database ?? ''),
                'key_present' => $service?->hasServiceKey() ?? false,
            ];
            if (! $service instanceof Service || ! $services[$key]['active'] || ! $services[$key]['key_present']
                || $services[$key]['database'] !== (string) $connections[$key]['database']) {
                throw new RuntimeException(ucfirst($key).' service final validation failed.');
            }
        }

        $schemaErrors = $this->schemaValidator->validatePortableSchema();
        if ($schemaErrors !== []) {
            throw new RuntimeException('SEO portable schema validation failed: '.implode('; ', $schemaErrors));
        }
        $nonEmpty = $this->schemaValidator->nonEmptyPortableTables();
        if ($nonEmpty !== []) {
            throw new RuntimeException('SEO portable business data is not empty: '.implode(', ', array_keys($nonEmpty)));
        }

        return ['services' => $services, 'schema_ready' => true, 'business_data_empty' => true];
    }

    private function migrationFailure(array $connections, string $details): string
    {
        return 'Migration failed (core='.(string) $connections['core']['database']
            .', seo='.(string) $connections['seo']['database']
            .', seeding='.(string) $connections['seeding']['database'].'): '.trim($details);
    }
}
