<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Core\Settings\CoreSettingsBootstrap;
use App\Core\Settings\SettingsSectionRegistry;
use App\Models\User;
use Filament\Pages\Page;

/**
 * Canonical Core Settings entry — redirects into the full settings shell (General).
 * Does NOT render a mini card dashboard.
 */
final class CoreSettingsHub extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Hệ thống';

    protected static ?string $navigationLabel = 'Cài đặt';

    protected static ?string $title = 'Cài đặt';

    protected static ?string $slug = 'settings';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.core-settings-hub';

    public static function canAccess(): bool
    {
        $role = (string) (auth()->user()?->role ?? '');

        return in_array($role, [User::ROLE_OWNER, User::ROLE_ADMIN], true);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        /** @var SettingsSectionRegistry $registry */
        $registry = app(SettingsSectionRegistry::class);
        app(CoreSettingsBootstrap::class)->seed($registry);

        $items = $registry->menuItems();
        $target = $items[0]['url'] ?? url('/admin/settings/general');
        foreach ($items as $item) {
            if (($item['id'] ?? '') === 'general') {
                $target = $item['url'];
                break;
            }
        }

        $this->redirect($target, navigate: false);
    }
}
