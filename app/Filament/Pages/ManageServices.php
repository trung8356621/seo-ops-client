<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * Local service activation UI is disabled.
 * Service.is_active remains a runtime snapshot; ops-server will own entitlement later.
 */
class ManageServices extends Page
{
    protected static ?string $navigationGroup = null;

    protected static ?int $navigationSort = 999;

    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static string $view = 'filament.pages.manage-services';

    public static function getNavigationGroup(): ?string
    {
        return __('site-service.system_nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('site-service.manage_services_nav');
    }

    public function getTitle(): string
    {
        return __('site-service.manage_services_title');
    }

    public $services = [];

    public function mount()
    {
        abort(403);
    }

    public function toggleService($id)
    {
        abort(403);
    }

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return false;
    }
}
