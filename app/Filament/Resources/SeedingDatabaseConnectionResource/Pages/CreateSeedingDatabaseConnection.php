<?php

declare(strict_types=1);

namespace App\Filament\Resources\SeedingDatabaseConnectionResource\Pages;

use App\Filament\Resources\SeedingDatabaseConnectionResource;
use App\Models\SeedingDatabaseConnection;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;
use Omnichannel\Addons\Seeding\Services\SeedingDatabaseConnectionService;
use RuntimeException;
use Throwable;

final class CreateSeedingDatabaseConnection extends CreateRecord
{
    protected static string $resource = SeedingDatabaseConnectionResource::class;

    public function mount(): void
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
        ];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['type'] = $data['type'] ?? 'manual';
        $this->assertConnectionTest($data);

        if (! empty($data['is_active'])) {
            SeedingDatabaseConnection::query()->update(['is_active' => false]);
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        try {
            /** @var SeedingDatabaseConnection $record */
            $record = $this->record;
            app(SeedingDatabaseConnectionService::class)->bootstrap($record, forceReconnect: true);
        } catch (Throwable) {
            // Keep saved credentials; health UI will show unreachable.
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertConnectionTest(array $data): void
    {
        $plainPassword = trim((string) ($data['password'] ?? ''));
        if ($plainPassword === '') {
            throw ValidationException::withMessages([
                'password' => 'Mật khẩu database là bắt buộc khi tạo kết nối.',
            ]);
        }

        try {
            app(SeedingDatabaseConnectionService::class)
                ->testConnectionFromAttributes($data, $plainPassword);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'database' => $exception->getMessage(),
            ]);
        }
    }

    private function runConnectionTest(): void
    {
        try {
            $this->assertConnectionTest($this->form->getState());
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Kết nối thất bại')
                ->body(collect($exception->errors())->flatten()->first() ?? 'Lỗi không xác định.')
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
