<?php

declare(strict_types=1);

namespace App\Filament\Resources\SeoDatabaseConnectionResource\Pages;

use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseBackupService;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use App\Filament\Resources\SeoDatabaseConnectionResource;
use App\Filament\Support\SeoDatabaseConnectionAccess;
use App\Filament\Support\SeoDatabaseConnectionBackupActions;
use App\Filament\Support\SeoDatabaseConnectionOwnerSync;
use App\Models\SeoDatabaseConnection;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class EditSeoDatabaseConnection extends EditRecord
{
    protected static string $resource = SeoDatabaseConnectionResource::class;

    public function mount(int|string $record): void
    {
        $this->redirect(\App\Filament\Pages\ServiceConfigure::getUrl(['service' => 'seo']));
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportSql')
                ->label(__('site-service.export_sql_label'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->visible(fn (): bool => $this->record instanceof SeoDatabaseConnection
                    && SeoDatabaseConnectionAccess::canEditConnection($this->record)
                    && (bool) $this->record->is_active)
                ->action(fn (): BinaryFileResponse => app(SeoDatabaseBackupService::class)->downloadResponse($this->record)),

            Actions\Action::make('importSql')
                ->label(__('site-service.import_sql_label'))
                ->icon('heroicon-o-arrow-up-tray')
                ->color('danger')
                ->visible(fn (): bool => $this->record instanceof SeoDatabaseConnection
                    && SeoDatabaseConnectionAccess::canEditConnection($this->record))
                ->modalHeading(__('site-service.seo_connection_restore_from_sql_modal_heading'))
                ->modalSubmitActionLabel(__('site-service.seo_connection_restore_from_sql_submit_label'))
                ->requiresConfirmation()
                ->form(SeoDatabaseConnectionBackupActions::importFormSchema())
                ->action(fn (array $data): mixed => SeoDatabaseConnectionBackupActions::runImport($data, $this->record)),

            Actions\Action::make('runMigrations')
                ->label(__('site-service.seo_connection_run_migrations_label'))
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription(__('site-service.seo_connection_run_migrations_modal_description_short'))
                ->action(fn (): mixed => $this->runPendingMigrations()),

            Actions\Action::make('testConnection')
                ->label(__('site-service.connection_test'))
                ->icon('heroicon-o-signal')
                ->color('gray')
                ->action(fn (): mixed => $this->runConnectionTest()),

            Actions\DeleteAction::make()
                ->visible(fn (): bool => $this->record instanceof SeoDatabaseConnection
                    && SeoDatabaseConnectionAccess::canDeleteConnection($this->record)),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['owner_id'] = auth()->id();

        /** @var SeoDatabaseConnection $record */
        $record = $this->record;

        $ownerId = SeoDatabaseConnectionOwnerSync::resolveOwnerId(
            $data,
            $record,
            data_get($this->form->getRawState(), 'owner_id'),
        );

        SeoDatabaseConnectionOwnerSync::assertOwnerEligible($ownerId);
        SeoDatabaseConnectionOwnerSync::assertOwnerSingleConnection($ownerId, (int) $record->getKey());

        $this->assertConnectionTest($data);

        unset($data['owner_id']);

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var SeoDatabaseConnection $record */
        $record = $this->record;

        $ownerId = SeoDatabaseConnectionOwnerSync::resolveOwnerId(
            $this->form->getState(),
            $record,
            data_get($this->form->getRawState(), 'owner_id'),
        );

        if ($ownerId > 0) {
            SeoDatabaseConnectionOwnerSync::syncOwner($record, $ownerId);
        }
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var SeoDatabaseConnection $record */
        $record = $this->record;
        $data['owner_id'] = (int) ($record->users()->value('users.id') ?? 0);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertConnectionTest(array $data): void
    {
        /** @var SeoDatabaseConnection $record */
        $record = $this->record;
        $service = app(SeoDatabaseConnectionService::class);
        $plainPassword = trim((string) ($data['password'] ?? ''));

        $attributes = array_merge($record->toArray(), $data);

        try {
            if ($plainPassword !== '') {
                $service->testConnectionFromAttributes($attributes, $plainPassword);
            } else {
                $merged = $record->replicate();
                $merged->fill($data);
                $service->testConnectionForModel($merged);
            }
        } catch (RuntimeException $exception) {
            $field = ($data['type'] ?? '') === 'manual' ? 'database' : 'type';

            throw ValidationException::withMessages([
                $field => $exception->getMessage(),
            ]);
        }
    }

    private function runConnectionTest(): void
    {
        try {
            $this->assertConnectionTest($this->form->getState());
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(__('site-service.connection_failed'))
                ->body(collect($exception->errors())->flatten()->first() ?? __('site-service.unknown_error'))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('site-service.connection_success'))
            ->success()
            ->send();
    }

    private function runPendingMigrations(): void
    {
        try {
            /** @var SeoDatabaseConnection $record */
            $record = $this->record->fresh(['users']);
            $result = app(SeoDatabaseConnectionService::class)->runMigrationsForConnection($record);

            if (! $result['executed']) {
                Notification::make()
                    ->title(__('site-service.no_pending_migrations'))
                    ->success()
                    ->send();

                return;
            }

            Notification::make()
                ->title(__('site-service.migration_addon_executed'))
                ->body(__('site-service.migration_applied_pending_count', ['count' => (int) $result['pending']]))
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title(__('site-service.migration_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
