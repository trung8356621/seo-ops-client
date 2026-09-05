<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Control\ClientEnrollmentService;
use App\Enums\ClientControlStatus;
use App\Models\ClientControlState;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Schema;

/**
 * Connect this client installation to ops-server and show control status.
 * Does NOT manage Service entitlement (ops-server owns services.apply).
 */
class ControlServer extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationGroup = 'Hệ thống';

    protected static ?string $slug = 'control-server';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.control-server';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('client_control.nav');
    }

    public function getTitle(): string
    {
        return __('client_control.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && (string) $user->role === User::ROLE_OWNER;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->controlState();
        $this->form->fill([
            'control_server_url' => $state?->control_server_url ?? '',
            'api_key' => '',
        ]);
    }

    /**
     * @return array<string, Form>
     */
    protected function getForms(): array
    {
        return [
            'form' => $this->form(Form::make($this)),
        ];
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('control_server_url')
                    ->label(__('client_control.control_server_url'))
                    ->url()
                    ->required()
                    ->maxLength(255)
                    ->placeholder('https://ops.example.com')
                    ->visible(fn (): bool => $this->canEnroll()),
                Forms\Components\TextInput::make('api_key')
                    ->label(__('client_control.api_key'))
                    ->password()
                    ->revealable()
                    ->required()
                    ->dehydrated()
                    ->autocomplete('new-password')
                    ->helperText(__('client_control.api_key_help'))
                    ->visible(fn (): bool => $this->canEnroll()),
            ])
            ->statePath('data');
    }

    public function connect(ClientEnrollmentService $enrollment): void
    {
        abort_unless($this->canEnroll(), 403);

        $data = $this->form->getState();
        $result = $enrollment->enroll(
            (string) ($data['control_server_url'] ?? ''),
            (string) ($data['api_key'] ?? ''),
        );

        // Never keep API key in Livewire state after attempt.
        $this->data['api_key'] = '';
        $this->form->fill([
            'control_server_url' => (string) ($data['control_server_url'] ?? ''),
            'api_key' => '',
        ]);

        if (! $result->ok) {
            Notification::make()
                ->danger()
                ->title(__('client_control.enroll_failed_title'))
                ->body($result->message)
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('client_control.enroll_success_title'))
            ->body($result->message)
            ->send();
    }

    public function canEnroll(): bool
    {
        $state = $this->controlState();

        return $state === null
            || in_array($state->status, [ClientControlStatus::Unregistered, ClientControlStatus::Revoked], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function getInstallationViewData(): array
    {
        $state = $this->controlState();
        $status = $state?->status ?? ClientControlStatus::Unregistered;
        $isLocked = $status === ClientControlStatus::Locked;
        $isRevoked = $status === ClientControlStatus::Revoked;
        $isConnected = in_array($status, [ClientControlStatus::Active, ClientControlStatus::Locked], true);

        return [
            'status' => $status->value,
            'status_label' => match ($status) {
                ClientControlStatus::Unregistered => __('client_control.status_unregistered'),
                ClientControlStatus::Active => __('client_control.status_active'),
                ClientControlStatus::Locked => __('client_control.status_locked'),
                ClientControlStatus::Revoked => __('client_control.status_revoked'),
            },
            'control_lock_label' => $isLocked
                ? __('client_control.lock_locked')
                : __('client_control.lock_unlocked'),
            'is_locked' => $isLocked,
            'is_revoked' => $isRevoked,
            'is_connected' => $isConnected,
            'show_status_panel' => $isConnected || $isRevoked,
            'control_server_url' => $state?->control_server_url,
            'installation_id' => $state?->installation_id,
            'client_version' => (string) ($state?->client_version ?: config('client_control.client_version')),
            'services_revision' => $state?->services_revision,
            'last_command_id' => $state?->last_command_id,
            'last_command_at' => $state?->last_command_at?->timezone(config('app.timezone'))->toDateTimeString(),
            'locked_at' => $state?->locked_at?->timezone(config('app.timezone'))->toDateTimeString(),
            'connected_at' => $state?->connected_at?->timezone(config('app.timezone'))->toDateTimeString(),
            'can_enroll' => $this->canEnroll(),
        ];
    }

    private function controlState(): ?ClientControlState
    {
        if (! Schema::hasTable('client_control_state')) {
            return null;
        }

        return ClientControlState::query()->orderBy('id')->first();
    }
}
