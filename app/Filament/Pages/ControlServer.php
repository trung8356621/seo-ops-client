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
                    ->visible(fn (): bool => $this->canEnroll()),
                Forms\Components\TextInput::make('api_key')
                    ->label(__('client_control.api_key'))
                    ->password()
                    ->revealable()
                    ->required()
                    ->dehydrated()
                    ->autocomplete('new-password')
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

        return [
            'status' => $state?->status->value ?? ClientControlStatus::Unregistered->value,
            'control_server_url' => $state?->control_server_url,
            'installation_id' => $state?->installation_id,
            'client_version' => (string) config('client_control.client_version'),
            'last_command_id' => $state?->last_command_id,
            'last_command_at' => $state?->last_command_at?->timezone(config('app.timezone'))->toDateTimeString(),
            'is_locked' => $state?->isLocked() ?? false,
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
