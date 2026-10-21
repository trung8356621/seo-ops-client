<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Legacy /admin/gateway → consolidated Service UI at /admin/services.
 * Backend Service Gate / API Access Gate logic is preserved outside this page.
 */
final class Gateway extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $slug = 'gateway';

    protected static ?int $navigationSort = 8;

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.gateway-redirect';

    public static function getNavigationLabel(): string
    {
        return 'Gateway';
    }

    public function getTitle(): string
    {
        return 'Gateway';
    }

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && in_array((string) $user->role, [User::ROLE_OWNER, User::ROLE_ADMIN], true);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->redirect(ServiceStatusOverview::getUrl(), navigate: false);
    }
}
