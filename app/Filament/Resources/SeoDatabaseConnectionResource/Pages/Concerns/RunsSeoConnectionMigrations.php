<?php

declare(strict_types=1);

namespace App\Filament\Resources\SeoDatabaseConnectionResource\Pages\Concerns;

use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use App\Models\SeoDatabaseConnection;
use Filament\Actions;
use Filament\Notifications\Notification;
use Throwable;

trait RunsSeoConnectionMigrations
{
    protected function seoMigrationHeaderAction(): Actions\Action
    {
        return Actions\Action::make('runMigrations')
            ->label(__('site-service.seo_connection_run_migrations_label'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('site-service.seo_connection_run_migrations_modal_heading'))
            ->modalDescription(__('site-service.seo_connection_run_migrations_modal_description'))
            ->action(fn (): mixed => $this->runSeoConnectionMigrations(manual: true));
    }

    protected function runSeoConnectionMigrations(bool $manual = false, ?SeoDatabaseConnection $record = null): void
    {
        /** @var SeoDatabaseConnection|null $record */
        $record ??= $this->record ?? null;
        if (! $record instanceof SeoDatabaseConnection) {
            return;
        }

        $service = app(SeoDatabaseConnectionService::class);

        try {
            $result = $service->runMigrationsForConnection($record->fresh());
        } catch (Throwable $exception) {
            Notification::make()
                ->title(__('site-service.migration_failed'))
                ->body($this->formatMigrationError($exception->getMessage()))
                ->danger()
                ->send();

            return;
        }

        if (! ($result['executed'] ?? false)) {
            if ($manual) {
                Notification::make()
                    ->title(__('site-service.no_new_migrations'))
                    ->body(__('site-service.seo_connection_no_new_migrations_body'))
                    ->success()
                    ->send();
            }

            return;
        }

        Notification::make()
            ->title(__('site-service.migration_executed'))
            ->body(__('site-service.migration_applied_pending_count', ['count' => (int) ($result['pending'] ?? 0)]))
            ->success()
            ->send();
    }

    private function formatMigrationError(string $message): string
    {
        if (str_contains($message, 'already exists')) {
            return __('site-service.seo_connection_migration_error_already_exists', ['message' => $message]);
        }

        return $message;
    }
}
