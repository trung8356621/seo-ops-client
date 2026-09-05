<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Pages\ServiceConfigure;
use App\Models\Service;
use App\Models\User;
use App\Services\ServiceDatabaseConnectionResolver;
use App\Services\ServiceIdentity;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Compact Admin dashboard shortcuts for SEO + Seeding (read-only).
 */
final class ServiceQuickShortcutsWidget extends Widget
{
    protected static ?int $sort = -20;

    protected int|string|array $columnSpan = 'full';

    protected static string $view = 'filament.widgets.service-quick-shortcuts';

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && in_array((string) $user->role, [User::ROLE_OWNER, User::ROLE_ADMIN], true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function cards(): array
    {
        $resolver = app(ServiceDatabaseConnectionResolver::class);
        $cards = [];

        foreach (ServiceIdentity::knownPublicSlugs() as $publicSlug) {
            if ($publicSlug === ServiceIdentity::PUBLIC_SEO && ! $this->seoVisible()) {
                continue;
            }
            if ($publicSlug === ServiceIdentity::PUBLIC_SEEDING && ! $this->seedingVisible()) {
                continue;
            }

            $health = $resolver->healthReport($publicSlug);
            $cards[] = [
                'name' => $health['name'],
                'slug' => $publicSlug,
                'open_url' => ServiceIdentity::openUrl($publicSlug),
                'setup_url' => ServiceConfigure::getUrl(['service' => $publicSlug]),
                'badge' => match (true) {
                    ! $health['active'] => 'Inactive',
                    ! $health['key_provisioned'] => 'Key thiếu',
                    ($health['connection_source'] ?? '') === 'canonical' && $health['database_reachable'] => 'Sẵn sàng',
                    ($health['connection_source'] ?? '') === 'canonical' && $health['database_configured'] => 'DB lỗi',
                    default => 'Chưa cấu hình DB',
                },
                'db_label' => $health['readiness_label'] ?? 'Chưa cấu hình',
                'key_label' => $health['key_provisioned'] ? 'Key ✓' : 'Key —',
            ];
        }

        return $cards;
    }

    private function seoVisible(): bool
    {
        if (! Schema::hasTable('services')) {
            return true;
        }

        return Service::query()->whereIn('slug', ServiceIdentity::catalogSlugsFor('seo'))->exists();
    }

    private function seedingVisible(): bool
    {
        if (! Schema::hasTable('services')) {
            return false;
        }

        return Service::query()->where('slug', ServiceIdentity::PUBLIC_SEEDING)->exists();
    }
}
