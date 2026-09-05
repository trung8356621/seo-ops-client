<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Service;
use App\Models\ServiceDatabaseConnection;
use App\Models\User;
use App\Services\ServiceDatabaseConnectionResolver;
use App\Services\ServiceDatabasePasswordIntent;
use App\Services\ServiceIdentity;
use Filament\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Throwable;

/**
 * Generic Service detail: status + one DB connection upsert (no entitlement controls).
 */
final class ServiceConfigure extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $slug = 'services/{service}';

    protected static string $view = 'filament.pages.service-configure';

    protected static bool $shouldRegisterNavigation = false;

    public string $service = '';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(array $parameters = []): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && in_array((string) $user->role, [User::ROLE_OWNER, User::ROLE_ADMIN], true);
    }

    public function mount(string $service): void
    {
        abort_unless(in_array($service, ServiceIdentity::knownPublicSlugs(), true), 404);
        $this->service = $service;

        $row = app(ServiceDatabaseConnectionResolver::class)->resolve($service);
        $this->form->fill([
            'host' => $row?->host ?? '127.0.0.1',
            'port' => $row?->port ?? '3306',
            'database' => $row?->database ?? ServiceIdentity::defaultLogicalConnection($service),
            'username' => $row?->username ?? '',
            'password' => '',
            'clear_password' => false,
            'is_active' => $row?->is_active ?? true,
            'type' => $row?->type ?? 'manual',
        ]);
    }

    public function getTitle(): string
    {
        return 'Cấu hình '.ServiceIdentity::displayName($this->service);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Database')
                    ->description('Một Service tối đa một kết nối DB. Mật khẩu được phép để trống (MySQL local root không password).')
                    ->schema([
                        TextInput::make('database')->label('Database name')->required(),
                        TextInput::make('host')->label('Host')->required(),
                        TextInput::make('port')->label('Port')->required(),
                        TextInput::make('username')->label('Username')->required(),
                        TextInput::make('password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->helperText(fn (): string => $this->existingConnection() instanceof ServiceDatabaseConnection
                                ? 'Để trống = giữ mật khẩu đã lưu. Tick “Không dùng mật khẩu” để xóa.'
                                : 'Để trống = không dùng mật khẩu (hợp lệ nếu MySQL chấp nhận).'),
                        Toggle::make('clear_password')
                            ->label('Không dùng mật khẩu / xóa mật khẩu đã lưu')
                            ->visible(fn (): bool => $this->existingConnection() instanceof ServiceDatabaseConnection)
                            ->live()
                            ->afterStateUpdated(function (?bool $state, callable $set): void {
                                if ($state) {
                                    $set('password', '');
                                }
                            }),
                        Toggle::make('is_active')->label('Kích hoạt')->default(true),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return app(ServiceDatabaseConnectionResolver::class)->healthReport($this->service);
    }

    public function catalogService(): ?Service
    {
        return ServiceIdentity::findService($this->service);
    }

    public function existingConnection(): ?ServiceDatabaseConnection
    {
        $service = $this->catalogService();

        return $service instanceof Service
            ? app(ServiceDatabaseConnectionResolver::class)->connectionForService($service)
            : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')
                ->label('Mở '.ServiceIdentity::displayName($this->service))
                ->url(ServiceIdentity::openUrl($this->service))
                ->openUrlInNewTab(),
            Action::make('test')
                ->label('Kiểm tra kết nối')
                ->color('gray')
                ->action(fn () => $this->testConnection()),
            Action::make('save')
                ->label('Lưu')
                ->action(fn () => $this->save()),
        ];
    }

    public function testConnection(): void
    {
        try {
            $this->runDraftTest($this->form->getState());
        } catch (RuntimeException $e) {
            Notification::make()
                ->title('Kết nối thất bại')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()->title('Kết nối thành công')->success()->send();
    }

    public function save(): void
    {
        $service = $this->catalogService();
        if (! $service instanceof Service) {
            Notification::make()->title('Service chưa được provision')->danger()->send();

            return;
        }

        $state = $this->form->getState();
        $existing = app(ServiceDatabaseConnectionResolver::class)->connectionForService($service);
        $intent = ServiceDatabasePasswordIntent::fromFormState(
            $state,
            $existing instanceof ServiceDatabaseConnection,
        );

        try {
            $this->runDraftTest($state);
        } catch (RuntimeException $e) {
            Notification::make()
                ->title('Không lưu — kết nối thất bại')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        try {
            $row = app(ServiceDatabaseConnectionResolver::class)->upsert($service, $state, $intent);
            $row->forceFill([
                'last_tested_at' => now(),
                'last_test_ok' => true,
                'last_error' => null,
            ])->save();

            if (blank($service->db_connection) || $service->db_connection === 'mysql') {
                $service->forceFill([
                    'db_connection' => ServiceIdentity::defaultLogicalConnection($this->service),
                ])->save();
            }

            app(ServiceDatabaseConnectionResolver::class)->bootstrap($this->service, forceReconnect: true);
        } catch (Throwable $e) {
            Notification::make()->title('Lưu thất bại')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Đã lưu cấu hình DB')->success()->send();
        $this->fillFormFromSaved();
    }

    /**
     * Test EXACT form draft — no env/legacy fallback.
     *
     * @param  array<string, mixed>  $state
     */
    private function runDraftTest(array $state): void
    {
        $existing = $this->existingConnection();
        $intent = ServiceDatabasePasswordIntent::fromFormState(
            $state,
            $existing instanceof ServiceDatabaseConnection,
        );
        $plain = ServiceDatabasePasswordIntent::plainForTest(
            $intent,
            $existing instanceof ServiceDatabaseConnection ? (string) ($existing->password ?? '') : null,
        );

        app(ServiceDatabaseConnectionResolver::class)->testDraftAttributes($state, $plain);
    }

    private function fillFormFromSaved(): void
    {
        $row = app(ServiceDatabaseConnectionResolver::class)->resolve($this->service);
        $this->form->fill([
            'host' => $row?->host ?? '127.0.0.1',
            'port' => $row?->port ?? '3306',
            'database' => $row?->database ?? ServiceIdentity::defaultLogicalConnection($this->service),
            'username' => $row?->username ?? '',
            'password' => '',
            'clear_password' => false,
            'is_active' => $row?->is_active ?? true,
            'type' => $row?->type ?? 'manual',
        ]);
    }
}
