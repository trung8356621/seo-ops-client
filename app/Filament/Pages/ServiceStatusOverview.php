<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Service;
use App\Models\User;
use App\Services\ServiceDatabaseConnectionResolver;
use App\Services\ServiceIdentity;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Read-only Admin Service catalog overview (no entitlement controls).
 */
final class ServiceStatusOverview extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?string $slug = 'services';

    protected static string $view = 'filament.pages.service-status-overview';

    protected static ?int $navigationSort = 11;

    public static function getNavigationGroup(): ?string
    {
        return 'Hệ thống';
    }

    public static function getNavigationLabel(): string
    {
        return 'Dịch vụ';
    }

    public function getTitle(): string
    {
        return 'Dịch vụ';
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && in_array((string) $user->role, [User::ROLE_OWNER, User::ROLE_ADMIN], true);
    }

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return static::canAccess();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function serviceCards(): array
    {
        $resolver = app(ServiceDatabaseConnectionResolver::class);
        $cards = [];

        foreach (ServiceIdentity::knownPublicSlugs() as $publicSlug) {
            $service = ServiceIdentity::findService($publicSlug);
            if (! $service instanceof Service && $publicSlug === ServiceIdentity::PUBLIC_SEEDING) {
                continue;
            }

            $health = $resolver->healthReport($publicSlug);
            $cards[] = [
                'slug' => $publicSlug,
                'name' => $health['name'],
                'active' => $health['active'],
                'exists' => $service instanceof Service,
                'key_label' => $health['key_provisioned'] ? 'Key ✓' : 'Key chưa provision',
                'db_connection' => $health['db_connection'],
                'db_label' => $health['readiness_label'] ?? 'Chưa cấu hình',
                'connection_source' => $health['connection_source'] ?? 'unavailable',
                'open_url' => ServiceIdentity::openUrl($publicSlug),
                'setup_url' => ServiceConfigure::getUrl(['service' => $publicSlug]),
            ];
        }

        return $cards;
    }
}
