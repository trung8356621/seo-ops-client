<?php

declare(strict_types=1);

namespace App\Core\Installation;

use App\Core\Database\AddonMigrationRegistrar;
use App\Core\Database\LegacyTolerantMigrationRunner;
use App\Core\Database\RefactorMigrationRunner;
use App\Core\Settings\CoreSettingsBootstrap;
use App\Core\Settings\SettingsSectionRegistry;
use App\Models\Service;
use App\Services\AddonManager;
use App\Services\Users\SeoOpsSystemUser;
use Illuminate\Support\Facades\Artisan;
use Throwable;

final class AppInitializer
{
    public function __construct(
        private readonly InstallationStateManager $stateManager,
        private readonly AddonMigrationRegistrar $migrationRegistrar,
        private readonly LegacyTolerantMigrationRunner $tolerantRunner,
        private readonly RefactorMigrationRunner $refactorRunner,
    ) {}

    /**
     * @param  callable(string): void|null  $logger
     * @return array{ok: bool, message: string, steps?: list<string>}
     */
    public function initialize(?callable $logger = null): array
    {
        $log = function (string $msg) use ($logger): void {
            if ($logger !== null) {
                $logger($msg);
            }
        };

        $stepsExecuted = [];

        try {
            $byConnection = $this->migrationRegistrar->pathsByConnection();

            // 1. Core + ai-prompt migrations on mysql connection
            $log('Running Core migrations on mysql connection...');
            $corePaths = $byConnection['mysql'] ?? [database_path('migrations')];
            $relativeCorePaths = $this->refactorRunner->toRelativePaths($corePaths);

            $result = $this->tolerantRunner->migrate(
                'mysql',
                $relativeCorePaths,
                false,
                fn (string $m) => $log($m),
            );

            if (! $result['ok']) {
                throw new \RuntimeException($result['failed'] ?? 'Core migration failed');
            }
            $stepsExecuted[] = 'core_migrations';

            // 2. SEO peer addon migrations on omi_seo_ai connection
            $seoPaths = $byConnection['omi_seo_ai'] ?? [];
            if (! empty($seoPaths)) {
                $log('Running approved SEO peer addon migrations on omi_seo_ai connection...');
                $relativeSeoPaths = $this->refactorRunner->toRelativePaths($seoPaths);
                $seoResult = $this->tolerantRunner->migrate(
                    'omi_seo_ai',
                    $relativeSeoPaths,
                    false,
                    fn (string $m) => $log($m),
                );
                if (! $seoResult['ok']) {
                    throw new \RuntimeException($seoResult['failed'] ?? 'SEO addon migration failed');
                }
            }
            $stepsExecuted[] = 'seo_migrations';

            // 3. Seeding migrations on omi_seeding connection
            $seedingPaths = $byConnection['omi_seeding'] ?? [];
            if (! empty($seedingPaths)) {
                $log('Running Seeding migrations on omi_seeding connection...');
                $relativeSeedingPaths = $this->refactorRunner->toRelativePaths($seedingPaths);
                $seedingResult = $this->tolerantRunner->migrate(
                    'omi_seeding',
                    $relativeSeedingPaths,
                    false,
                    fn (string $m) => $log($m),
                );
                if (! $seedingResult['ok']) {
                    throw new \RuntimeException($seedingResult['failed'] ?? 'Seeding migration failed');
                }
            }
            $stepsExecuted[] = 'seeding_migrations';

            // 4. Social Automation migrations on omi_social_automation connection
            $socialAutomationPaths = $byConnection['omi_social_automation'] ?? [];
            if (! empty($socialAutomationPaths)) {
                $log('Running Social Automation migrations on omi_social_automation connection...');
                $relativeSaPaths = $this->refactorRunner->toRelativePaths($socialAutomationPaths);
                $saResult = $this->tolerantRunner->migrate(
                    'omi_social_automation',
                    $relativeSaPaths,
                    false,
                    fn (string $m) => $log($m),
                );
                if (! $saResult['ok']) {
                    throw new \RuntimeException($saResult['failed'] ?? 'Social Automation migration failed');
                }
            }
            $stepsExecuted[] = 'social_automation_migrations';

            // 5. Register approved addons and Service Catalog metadata
            $log('Registering approved addons and Service Catalog metadata...');
            $discovered = AddonManager::discover();
            $log('Discovered addons: '.implode(', ', $discovered));

            // CRITICAL: All services registered in catalog must be INACTIVE on a fresh install
            Service::query()->update(['is_active' => false]);
            $log('All services registered in catalog as inactive (unlicensed).');
            $stepsExecuted[] = 'addon_discovery_service_catalog';

            // 6. Minimum system data
            $log('Ensuring system background user...');
            SeoOpsSystemUser::ensure();

            if (app()->bound(SettingsSectionRegistry::class) && app()->bound(CoreSettingsBootstrap::class)) {
                $log('Seeding core settings defaults...');
                app(CoreSettingsBootstrap::class)->seed(app(SettingsSectionRegistry::class));
            }
            $stepsExecuted[] = 'system_data';

            // Update state
            $current = $this->stateManager->readRecord() ?? [];
            $this->stateManager->saveRecord(array_merge($current, [
                'status' => InstallationState::CONFIGURING,
                'step' => 'admin',
                'completed_steps' => $stepsExecuted,
                'updated_at' => date('c'),
            ]));

            return [
                'ok' => true,
                'message' => 'Application initialized successfully.',
                'steps' => $stepsExecuted,
            ];
        } catch (Throwable $e) {
            $current = $this->stateManager->readRecord() ?? [];
            $this->stateManager->saveRecord(array_merge($current, [
                'status' => InstallationState::RECOVERABLE_FAILURE,
                'error' => $e->getMessage(),
                'completed_steps' => $stepsExecuted,
                'failed_at' => date('c'),
            ]));

            return [
                'ok' => false,
                'message' => 'Initialization failed: '.$e->getMessage(),
                'steps' => $stepsExecuted,
            ];
        }
    }
}
