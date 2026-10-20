<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Core\Performance\PerformancePreset;
use App\Core\Performance\PerformanceSettings;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

final class SystemPerformanceSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    protected static ?string $slug = 'settings/performance';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.system-performance-settings';

    /** @var array{preset?: string} */
    public array $data = [];

    /** Persisted preset (for “Current” badge). */
    public string $savedPreset = 'mid';

    public static function getNavigationLabel(): string
    {
        return __('performance.nav');
    }

    public function getTitle(): string
    {
        return __('performance.page_title');
    }

    public static function canAccess(): bool
    {
        $role = (string) (auth()->user()?->role ?? '');

        return in_array($role, [User::ROLE_OWNER, User::ROLE_ADMIN], true);
    }

    public function mount(PerformanceSettings $settings): void
    {
        abort_unless(static::canAccess(), 403);
        $preset = $settings->preset()->value;
        $this->savedPreset = $preset;
        $this->form->fill([
            PerformanceSettings::KEY_PRESET => $preset,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Hidden::make(PerformanceSettings::KEY_PRESET)
                    ->required()
                    ->in([
                        PerformancePreset::Low->value,
                        PerformancePreset::Mid->value,
                        PerformancePreset::High->value,
                    ]),
            ])
            ->statePath('data');
    }

    public function selectPreset(string $preset): void
    {
        abort_unless(static::canAccess(), 403);
        $resolved = PerformancePreset::tryFromMixed($preset);
        $this->form->fill([
            PerformanceSettings::KEY_PRESET => $resolved->value,
        ]);
    }

    public function save(PerformanceSettings $settings): void
    {
        abort_unless(static::canAccess(), 403);
        $state = $this->form->getState();
        $preset = $settings->savePreset($state[PerformanceSettings::KEY_PRESET] ?? PerformancePreset::default());
        $this->savedPreset = $preset->value;
        $this->data[PerformanceSettings::KEY_PRESET] = $preset->value;

        Notification::make()
            ->title(__('performance.saved_title'))
            ->body(__('performance.saved_body', ['preset' => __('performance.preset_'.$preset->value)]))
            ->success()
            ->send();
    }
}
