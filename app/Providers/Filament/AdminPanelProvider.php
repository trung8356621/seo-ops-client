<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\CustomLogin;
use App\Filament\Pages\HelpTopicCreate;
use App\Filament\Pages\HelpTopicEdit;
use App\Filament\Pages\HelpTopicsAdmin;
use App\Http\Middleware\Filament\RedirectStaffFromAdminPanel;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Omnichannel\Addons\Agent\Filament\Pages\AutomationFlowsPage;
use Omnichannel\Addons\Agent\Filament\Pages\AutomationSettings;
use Omnichannel\Addons\Agent\Filament\Pages\AutomationWorkflowBuilder;
use Omnichannel\Addons\Agent\Filament\Resources\AutomationExecutionResource;
use Omnichannel\Addons\Agent\Filament\Resources\AutomationRuleResource;
use Omnichannel\Addons\SearchFoundation\Filament\Pages\AutomationOperationsDashboard;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(CustomLogin::class)
            ->registration()
            ->passwordReset()
            ->emailVerification()
            ->brandLogo(asset('images/logo.png'))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('favicon.ico'))
            ->colors([
                'primary' => Color::Amber,
            ])
            ->maxContentWidth(MaxWidth::Full)
            ->navigationGroups([
                NavigationGroup::make('Quản lý'),
                NavigationGroup::make('Hệ thống'),
                NavigationGroup::make('Automation'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
                HelpTopicsAdmin::class,
                HelpTopicEdit::class,
                HelpTopicCreate::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                RedirectStaffFromAdminPanel::class,
                Authenticate::class,
            ]);

        return $this->discover_addons($panel);
    }

    /**
     * Register Automation product UI on /admin (owner-only shell).
     * SEO business UI stays on /seo; legacy /seo/automation/* redirects here.
     */
    private function discover_addons(Panel $panel): Panel
    {
        return $panel
            ->pages([
                Pages\Dashboard::class,
                HelpTopicsAdmin::class,
                HelpTopicEdit::class,
                HelpTopicCreate::class,
                AutomationFlowsPage::class,
                AutomationOperationsDashboard::class,
                AutomationSettings::class,
                AutomationWorkflowBuilder::class,
            ])
            ->resources([
                AutomationRuleResource::class,
                AutomationExecutionResource::class,
            ]);
    }
}
