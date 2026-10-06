<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\ClientTransfer\TargetSchemaValidator;
use App\Services\ImportLab\ImportLabDatabaseAdmin;
use App\Services\ImportLab\ImportLabResetWorkflow;
use App\Services\ImportLab\ImportLabRuntime;
use App\Services\ImportLab\ImportLabSafetyGuard;
use App\Services\ImportLab\LaravelImportLabResetWorkflow;
use App\Services\ServiceDatabaseConnectionResolver;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class ResetClientImportLabCommandTest extends TestCase
{
    private const SAFE_DATABASES = [
        'core' => 'client_import_lab_core_c83',
        'seo' => 'seo_import_lab_a91',
        'seeding' => 'seeding_import_lab_b47',
    ];

    public function test_refuses_non_local_environment(): void
    {
        $this->expectException(RuntimeException::class);
        (new ImportLabSafetyGuard)->assertSafe('production', 'C:\\laragon\\www\\seo-ops-import-test', self::SAFE_DATABASES);
    }

    #[DataProvider('safeDatabaseNames')]
    public function test_accepts_current_lab_database_names(string $database): void
    {
        self::assertTrue((new ImportLabSafetyGuard)->isSafeLabDatabaseName($database));
    }

    public static function safeDatabaseNames(): array
    {
        return [
            ['client_import_lab_core_c83'],
            ['seo_import_lab_a91'],
            ['seeding_import_lab_b47'],
        ];
    }

    #[DataProvider('unsafeDatabaseNames')]
    public function test_refuses_unsafe_and_normal_database_names(string $database): void
    {
        $names = self::SAFE_DATABASES;
        $names['seo'] = $database;

        $this->expectException(RuntimeException::class);
        (new ImportLabSafetyGuard)->assertSafe('local', 'C:\\laragon\\www\\seo-ops-import-test', $names);
    }

    public static function unsafeDatabaseNames(): array
    {
        return [
            [''],
            ['omi'],
            ['omi_seo_ai'],
            ['omi_seeding'],
            ['production'],
            ['client_import_lab'],
            ['import_lab_test'],
            ['foo_import_lab_'],
            ['normal_dev_database'],
        ];
    }

    public function test_all_names_are_validated_before_destructive_work(): void
    {
        $workflow = Mockery::mock(ImportLabResetWorkflow::class);
        $workflow->shouldNotReceive('run');
        $this->app->instance(ImportLabResetWorkflow::class, $workflow);
        $this->configureDatabases(['seeding' => 'omi_seeding']);

        $this->artisan('client-test:reset', ['--yes' => true])->assertFailed()->expectsOutputToContain('ABORT:');
    }

    public function test_confirmation_is_required_without_yes(): void
    {
        $this->allowSafetyGuard();
        $workflow = Mockery::mock(ImportLabResetWorkflow::class);
        $workflow->shouldNotReceive('run');
        $this->app->instance(ImportLabResetWorkflow::class, $workflow);
        $this->configureDatabases();

        $this->artisan('client-test:reset')
            ->expectsConfirmation('Drop and recreate all three import-lab databases?', 'no')
            ->assertFailed();
    }

    public function test_migration_failure_prevents_service_bootstrap(): void
    {
        $admin = Mockery::mock(ImportLabDatabaseAdmin::class);
        $admin->shouldReceive('recreate')->once();
        $runtime = Mockery::mock(ImportLabRuntime::class);
        $runtime->shouldReceive('clearConfig')->once();
        $runtime->shouldReceive('migrate')->once()->andThrow(new RuntimeException('broken migration'));
        $runtime->shouldNotReceive('bootstrapServices');

        $workflow = new LaravelImportLabResetWorkflow(
            $admin,
            $runtime,
            app(ServiceDatabaseConnectionResolver::class),
            app(TargetSchemaValidator::class),
        );

        $this->expectExceptionMessage('Migration failed');
        $workflow->run($this->connectionConfigs());
    }

    public function test_final_validation_detects_missing_service_connection(): void
    {
        $workflow = Mockery::mock(ImportLabResetWorkflow::class);
        $workflow->shouldReceive('run')->once()->andThrow(new RuntimeException('SEO service final validation failed.'));
        $this->app->instance(ImportLabResetWorkflow::class, $workflow);
        $this->allowSafetyGuard();
        $this->configureDatabases();

        $this->artisan('client-test:reset', ['--yes' => true])
            ->expectsOutputToContain('SEO service final validation failed')
            ->assertFailed();
    }

    public function test_safe_lab_configuration_reaches_successful_reset_flow(): void
    {
        $workflow = Mockery::mock(ImportLabResetWorkflow::class);
        $workflow->shouldReceive('run')->once()->andReturn([
            'services' => [
                'seo' => ['active' => true, 'database' => self::SAFE_DATABASES['seo'], 'key_present' => true],
                'seeding' => ['active' => true, 'database' => self::SAFE_DATABASES['seeding'], 'key_present' => true],
            ],
            'schema_ready' => true,
            'business_data_empty' => true,
        ]);
        $this->app->instance(ImportLabResetWorkflow::class, $workflow);
        $this->allowSafetyGuard();
        $this->configureDatabases();

        $this->artisan('client-test:reset', ['--yes' => true])
            ->expectsOutputToContain('Ready for import: YES')
            ->assertSuccessful();
    }

    private function allowSafetyGuard(): void
    {
        $guard = Mockery::mock(ImportLabSafetyGuard::class);
        $guard->shouldReceive('assertSafe')->once();
        $this->app->instance(ImportLabSafetyGuard::class, $guard);
    }

    /** @param array<string, string> $overrides */
    private function configureDatabases(array $overrides = []): void
    {
        foreach (array_merge(self::SAFE_DATABASES, $overrides) as $key => $database) {
            $connection = $key === 'core' ? 'mysql' : ($key === 'seo' ? 'omi_seo_ai' : 'omi_seeding');
            config()->set('database.connections.'.$connection.'.database', $database);
        }
        config()->set('database.core_connection', 'mysql');
    }

    /** @return array<string, array<string, mixed>> */
    private function connectionConfigs(): array
    {
        $this->configureDatabases();

        return [
            'core' => (array) config('database.connections.mysql'),
            'seo' => (array) config('database.connections.omi_seo_ai'),
            'seeding' => (array) config('database.connections.omi_seeding'),
        ];
    }
}
