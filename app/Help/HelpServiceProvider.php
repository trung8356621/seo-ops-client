<?php

declare(strict_types=1);

namespace App\Help;

use App\Models\User;
use Illuminate\Support\ServiceProvider;

final class HelpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(base_path('config/help.php'), 'help');

        $this->app->singleton(HelpCacheStore::class);
        $this->app->singleton(HelpGitHubClient::class);
        $this->app->singleton(HelpMarkdownDocument::class);
        $this->app->singleton(HelpMarkdownRenderer::class);
        $this->app->singleton(HelpHtmlToMarkdownConverter::class);
        $this->app->singleton(HelpRemoteSyncService::class);
        $this->app->singleton(HelpCoverageService::class);
        $this->app->singleton(HelpRuntimePayloadBuilder::class);
        $this->app->singleton(HelpPublishService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Console\Commands\HelpSyncCommand::class,
            ]);
        }
    }

    public static function userCanManageHelp(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $role = (string) $user->role;

        return $role === User::ROLE_OWNER || $role === User::ROLE_ADMIN;
    }
}
