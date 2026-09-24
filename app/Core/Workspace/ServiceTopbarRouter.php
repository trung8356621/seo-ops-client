<?php

declare(strict_types=1);

namespace App\Core\Workspace;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Shared Filament topbar service switcher — Admin / SEO / Seeding.
 * Authorization reuses WorkspaceDestinationRegistry + User::canAccessPanel.
 */
final class ServiceTopbarRouter
{
    /** Canonical cross-service keys (excludes Tools hub cards). */
    public const KEYS = ['admin', 'seo', 'seeding'];

    /** Panels that should show the shared router. */
    public const PANEL_IDS = ['admin', 'seo', 'seo-main', 'seeding'];

    public function __construct(
        private readonly WorkspaceDestinationRegistry $destinations,
    ) {}

    public function shouldRender(): bool
    {
        if (! Auth::check()) {
            return false;
        }

        try {
            $panelId = Filament::getCurrentPanel()?->getId();
        } catch (Throwable) {
            return false;
        }

        return is_string($panelId) && in_array($panelId, self::PANEL_IDS, true);
    }

    /**
     * Accessible service shortcuts for the authenticated user.
     *
     * @return list<array{
     *     key: string,
     *     label: string,
     *     url: string,
     *     active: bool,
     * }>
     */
    public function links(?User $user = null): array
    {
        $user ??= Auth::user();
        if (! $user instanceof User) {
            return [];
        }

        $active = $this->activeKey();
        $out = [];

        foreach (self::KEYS as $key) {
            $destination = $this->destinationFor($key);
            if ($destination === null || ! $destination->allows($user)) {
                continue;
            }

            $out[] = [
                'key' => $key,
                'label' => $this->labelFor($key),
                'url' => $destination->url,
                'active' => $active === $key,
            ];
        }

        return $out;
    }

    public function activeKey(): ?string
    {
        $path = trim(request()->path(), '/');
        if ($path === 'admin' || str_starts_with($path, 'admin/')) {
            return 'admin';
        }
        if ($path === 'seeding' || str_starts_with($path, 'seeding/')) {
            return 'seeding';
        }
        if ($path === 'seo' || str_starts_with($path, 'seo/')) {
            return 'seo';
        }

        try {
            $panelId = Filament::getCurrentPanel()?->getId();
        } catch (Throwable) {
            return null;
        }

        return match ($panelId) {
            'admin' => 'admin',
            'seeding' => 'seeding',
            'seo', 'seo-main' => 'seo',
            default => null,
        };
    }

    private function destinationFor(string $key): ?WorkspaceDestination
    {
        foreach ($this->destinations->all() as $destination) {
            if ($destination->key === $key) {
                return $destination;
            }
        }

        return $this->fallbackDestination($key);
    }

    private function fallbackDestination(string $key): ?WorkspaceDestination
    {
        return match ($key) {
            'admin' => new WorkspaceDestination(
                key: 'admin',
                label: 'Admin',
                url: url('/admin'),
                sort: 0,
                panelId: 'admin',
            ),
            'seo' => new WorkspaceDestination(
                key: 'seo',
                label: 'SEO',
                url: url('/seo'),
                sort: 10,
                panelId: 'seo',
                canAccess: static function (User $user): bool {
                    return $user->canAccessSeoPanel();
                },
            ),
            'seeding' => new WorkspaceDestination(
                key: 'seeding',
                label: 'Seeding',
                url: url('/seeding'),
                sort: 20,
                panelId: 'seeding',
            ),
            default => null,
        };
    }

    private function labelFor(string $key): string
    {
        return match ($key) {
            'admin' => (string) __('services.admin'),
            'seo' => (string) __('services.seo'),
            'seeding' => (string) __('services.seeding'),
            default => $key,
        };
    }
}
