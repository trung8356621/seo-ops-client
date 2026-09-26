<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseBackupService;
use App\Models\SeoDatabaseConnection;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

final class SeoDatabaseConnectionBackupActions
{
    public static function exportTableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('exportSql')
            ->label(__('site-service.export_sql_label'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('success')
            ->visible(fn (SeoDatabaseConnection $record): bool => (bool) $record->is_active)
            ->action(fn (SeoDatabaseConnection $record): BinaryFileResponse => app(SeoDatabaseBackupService::class)->downloadResponse($record))
            ->successNotificationTitle(__('site-service.seo_connection_backup_downloading'));
    }

    public static function importTableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('importSql')
            ->label(__('site-service.import_sql_label'))
            ->icon('heroicon-o-arrow-up-tray')
            ->color('danger')
            ->modalHeading(__('site-service.seo_connection_restore_from_sql_modal_heading'))
            ->modalSubmitActionLabel(__('site-service.seo_connection_restore_from_sql_submit_label'))
            ->requiresConfirmation()
            ->form(self::importFormSchema())
            ->action(fn (array $data, SeoDatabaseConnection $record): mixed => self::runImport($data, $record));
    }

    /**
     * @return list<\Filament\Forms\Components\Component>
     */
    public static function importFormSchema(): array
    {
        return [
            Forms\Components\Placeholder::make('import_warning')
                ->label('')
                ->content(new HtmlString(
                    '<div class="rounded-lg border border-danger-300 bg-danger-50 px-4 py-3 text-sm text-danger-800 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-200">'
                    .'<strong>'.e(__('site-service.warning')).':</strong> '
                    .e(__('site-service.seo_connection_import_warning_body'))
                    .'</div>',
                )),

            Forms\Components\FileUpload::make('backup_file')
                ->label(__('site-service.seo_connection_backup_file_label'))
                ->disk('local')
                ->directory('seo-db-imports')
                ->required()
                ->maxSize((int) config('seo-content-ai.db_import_max_upload_kb', 512000))
                ->acceptedFileTypes([
                    'application/sql',
                    'text/plain',
                    'application/gzip',
                    'application/x-gzip',
                    'application/octet-stream',
                ])
                ->helperText(__('site-service.seo_connection_backup_file_helper')),

            Forms\Components\Toggle::make('force_queue')
                ->label(__('site-service.seo_connection_force_queue_label'))
                ->default(false),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function runImport(array $data, SeoDatabaseConnection $record): void
    {
        $relativePath = Arr::first((array) ($data['backup_file'] ?? []));
        if (! is_string($relativePath) || $relativePath === '') {
            Notification::make()
                ->title(__('site-service.import_failed'))
                ->body(__('site-service.seo_connection_select_backup_file'))
                ->danger()
                ->send();

            return;
        }

        $backupService = app(SeoDatabaseBackupService::class);

        try {
            $absolutePath = $backupService->resolveStoredImportPath($relativePath);
            $result = $backupService->importConnection(
                $record,
                $absolutePath,
                forceQueue: (bool) ($data['force_queue'] ?? false),
            );
        } catch (Throwable $exception) {
            Notification::make()
                ->title(__('site-service.import_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        if ($result['queued']) {
            Notification::make()
                ->title(__('site-service.import_queued'))
                ->body(__('site-service.seo_connection_import_queued_body', ['id' => (string) ($result['task_job_id'] ?? '—')]))
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('site-service.import_completed'))
            ->body(__('site-service.seo_connection_import_completed_body', ['count' => (int) $result['statements']]))
            ->success()
            ->send();
    }
}
