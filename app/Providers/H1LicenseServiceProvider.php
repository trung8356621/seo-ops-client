<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\H1EnrollCommand;
use App\Console\Commands\H1ReconcileCommand;
use App\Core\Addon\AddonEntitlementGate;
use App\Services\ConfigurationSync\H1InstallationMachineCredentialProvider;
use App\Services\H1\Commands\EntitlementCommandApplier;
use App\Services\H1\Commands\RotationCommandApplier;
use App\Services\H1\Enforcement\ServiceEntitlementGate;
use App\Services\H1\Enrollment\BlockedEnrollmentTransport;
use App\Services\H1\Enrollment\EnrollmentConsumer;
use App\Services\H1\Enrollment\EnrollmentTransport;
use App\Services\H1\Enrollment\FixtureEnrollmentTransport;
use App\Services\H1\Enrollment\HttpEnrollmentTransport;
use App\Services\H1\Persistence\InstallationStateRepository;
use App\Services\H1\Protocol\SignedHttpTransport;
use App\Services\H1\Reconcile\LicenseReconcileService;
use App\Services\H1\Rotation\BlockedRotationSecretTransport;
use App\Services\H1\Rotation\HttpRotationSecretTransport;
use App\Services\H1\Rotation\RotationSecretTransport;
use App\Services\H1\Sites\AdminSiteStatusPolicy;
use App\Services\H1\Snapshot\EntitlementSnapshotMapper;
use Illuminate\Support\ServiceProvider;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\InstallationMachineCredentialProvider;

final class H1LicenseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(config_path('h1-license.php'), 'h1-license');

        $this->app->singleton(InstallationStateRepository::class);
        $this->app->singleton(EntitlementSnapshotMapper::class);
        $this->app->singleton(SignedHttpTransport::class);
        $this->app->singleton(AdminSiteStatusPolicy::class);
        $this->app->singleton(ServiceEntitlementGate::class);
        $this->app->singleton(RotationCommandApplier::class);
        $this->app->singleton(EntitlementCommandApplier::class);
        $this->app->singleton(EnrollmentConsumer::class);
        $this->app->singleton(LicenseReconcileService::class);

        $this->app->singleton(EnrollmentTransport::class, function ($app) {
            $driver = (string) config('h1-license.enrollment.transport', 'blocked');
            if ($driver === 'http') {
                return $app->make(HttpEnrollmentTransport::class);
            }
            if ($driver === 'fixture' && $app->bound(FixtureEnrollmentTransport::class)) {
                return $app->make(FixtureEnrollmentTransport::class);
            }

            return $app->make(BlockedEnrollmentTransport::class);
        });

        $this->app->singleton(RotationSecretTransport::class, function ($app) {
            $driver = (string) config('h1-license.rotation.transport', 'blocked');
            if ($driver === 'http') {
                return $app->make(HttpRotationSecretTransport::class);
            }

            return $app->make(BlockedRotationSecretTransport::class);
        });

        $this->app->singleton(H1InstallationMachineCredentialProvider::class);
        $this->app->singleton(
            InstallationMachineCredentialProvider::class,
            H1InstallationMachineCredentialProvider::class,
        );

        $this->app->singleton(AddonEntitlementGate::class, function ($app) {
            $gate = $app->make(ServiceEntitlementGate::class);

            return new AddonEntitlementGate(static function (string $addonSlug) use ($gate): bool {
                $map = [
                    'seo' => 'seo',
                    'seo-content-ai' => 'seo',
                    'search-foundation' => 'seo',
                    'search-intelligence' => 'seo',
                    'seeding' => 'seeding',
                ];
                $service = $map[$addonSlug] ?? null;
                if ($service === null) {
                    return true;
                }

                return $gate->allows($service);
            });
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                H1EnrollCommand::class,
                H1ReconcileCommand::class,
            ]);
        }
    }
}
