<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Pages\Page;

/**
 * Legacy admin alias for the retired Agent Workspace chat entry.
 * Agent Workspace is reference-only and is no longer a live runtime surface.
 */
final class AgentWorkspaceRedirect extends Page
{
    protected static ?string $slug = 'agent';

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?int $navigationSort = 39;

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.agent-workspace-redirect';

    public static function getNavigationLabel(): string
    {
        return 'Chat';
    }

    public function getTitle(): string
    {
        return 'Chat';
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessSeoPanel();
    }

    public function mount(): void
    {
        abort_unless(SeoAccessControl::canAccessSeoPanel(), 403);
        // Intentionally no redirect into Agent Workspace — runtime is isolated/reference-only.
    }

    public function getSeoAgentUrl(): ?string
    {
        return null;
    }

    public function getMissingSiteMessage(): string
    {
        return 'Agent Workspace is retired from the active runtime and is available only as reference source.';
    }
}
