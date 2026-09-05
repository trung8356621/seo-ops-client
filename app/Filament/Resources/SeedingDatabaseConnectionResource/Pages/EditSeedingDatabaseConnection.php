<?php

declare(strict_types=1);

namespace App\Filament\Resources\SeedingDatabaseConnectionResource\Pages;

use App\Filament\Resources\SeedingDatabaseConnectionResource;
use App\Models\SeedingDatabaseConnection;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;
use Omnichannel\Addons\Seeding\Services\SeedingDatabaseConnectionService;
use RuntimeException;
use Throwable;

final class EditSeedingDatabaseConnection extends EditRecord
{
    protected static string $resource = SeedingDatabaseConnectionResource::class;

    public function mount(int|string $record): void
    {
        $this->redirect(\App\Filament\Pages\ServiceConfigure::getUrl(['service' => 'seeding']));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testConnection')
                ->label('Kiểm tra kết nối')
                ->color('gray')
                ->action(fn () => $this->runConnectionTest()),
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var SeedingDatabaseConnection $record */
        $record = $this->record;
        $plainPassword = trim((string) ($data['password'] ?? ''));

        $probe = array_merge($record->only([
            'name', 'type', 'host', 'port', 'database', 'username', 'password', 'is_active',
        ]), $data);

        try {
            app(SeedingDatabaseConnectionService::class)->testConnectionFromAttributes(
                $probe,
                $plainPassword !== '' ? $plainPassword : null,
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'database' => $exception->getMessage(),
            ]);
        }

        if (! empty($data['is_active'])) {
            SeedingDatabaseConnection::query()
                ->whereKeyNot($record->getKey())
                ->update(['is_active' => false]);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        try {
            /** @var SeedingDatabaseConnection $record */
            $record = $this->record;
            app(SeedingDatabaseConnectionService::class)->bootstrap($record->fresh(), forceReconnect: true);
        } catch (Throwable) {
        }
    }

    private function runConnectionTest(): void
    {
        /** @var SeedingDatabaseConnection $record */
        $record = $this->record;
        $state = $this->form->getState();
        $plainPassword = trim((string) ($state['password'] ?? ''));

        try {
            app(SeedingDatabaseConnectionService::class)->testConnectionFromAttributes(
                array_merge($record->only([
                    'name', 'type', 'host', 'port', 'database', 'username', 'password', 'is_active',
                ]), $state),
                $plainPassword !== '' ? $plainPassword : null,
            );
        } catch (RuntimeException $exception) {
            Notification::make()
                ->title('Kết nối thất bại')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Kết nối thành công')
            ->success()
            ->send();
    }
}
