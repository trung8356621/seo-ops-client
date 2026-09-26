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
        return __('site-service.service_configure_title', ['service' => ServiceIdentity::displayName($this->service)]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make(__('site-service.service_configure_db_section_title'))
                    ->description(__('site-service.service_configure_db_section_description'))
                    ->schema([
                        TextInput::make('database')->label(__('site-service.database_name_label'))->required(),
                        TextInput::make('host')->label(__('site-service.host_label'))->required(),
                        TextInput::make('port')->label(__('site-service.port_label'))->required(),
                        TextInput::make('username')->label(__('site-service.username_label'))->required(),
                        TextInput::make('password')
                            ->label(__('site-service.password_label'))
                            ->password()
                            ->revealable()
                            ->helperText(fn (): string => $this->existingConnection() instanceof ServiceDatabaseConnection
                                ? __('site-service.service_configure_password_keep_helper')
                                : __('site-service.service_configure_password_blank_helper')),
                        Toggle::make('clear_password')
                            ->label(__('site-service.service_configure_clear_password_label'))
                            ->visible(fn (): bool => $this->existingConnection() instanceof ServiceDatabaseConnection)
                            ->live()
                            ->afterStateUpdated(function (?bool $state, callable $set): void {
                                if ($state) {
                                    $set('password', '');
                                }
                            }),
                        Toggle::make('is_active')->label(__('site-service.service_configure_active_label'))->default(true),
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
                ->label(__('site-service.service_configure_open', ['service' => ServiceIdentity::displayName($this->service)]))
                ->url(ServiceIdentity::openUrl($this->service))
                ->openUrlInNewTab(),
            Action::make('test')
                ->label(__('site-service.connection_test'))
                ->color('gray')
                ->action(fn () => $this->testConnection()),
            Action::make('save')
                ->label(__('site-service.save'))
                ->action(fn () => $this->save()),
        ];
    }

    public function testConnection(): void
    {
        try {
            $this->runDraftTest($this->form->getState());
        } catch (RuntimeException $e) {
            Notification::make()
                ->title(__('site-service.connection_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()->title(__('site-service.connection_success'))->success()->send();
    }

    public function save(): void
    {
        $service = $this->catalogService();
        if (! $service instanceof Service) {
            Notification::make()->title(__('site-service.service_not_provisioned'))->danger()->send();

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
                ->title(__('site-service.save_failed_connection_failed'))
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
            Notification::make()->title(__('site-service.save_failed'))->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title(__('site-service.saved_db_configuration'))->success()->send();
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
