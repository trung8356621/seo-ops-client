<?php

namespace App\Filament\Resources\IndustryContextProfileResource\Pages;

use App\Filament\Resources\IndustryContextProfileResource;
use App\Filament\Support\IndustryContextClipboard;
use App\Filament\Support\ValidatesIndustryContextJson;
use App\IndustryContext\IndustryContextExpiry;
use App\IndustryContext\IndustryMarketOptions;
use Filament\Actions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Get;
use Filament\Resources\Pages\EditRecord;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\IndustryContextGenerationService;

final class EditIndustryContextProfile extends EditRecord
{
    use ValidatesIndustryContextJson;

    protected static string $resource = IndustryContextProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('quick_generate')->label('Gen nhanh')->icon('heroicon-o-sparkles')
                ->form([
                    Textarea::make('notes')->label(__('Temporary notes')),
                    Select::make('market')->label('Thị trường mục tiêu')->options(IndustryMarketOptions::options())->searchable()
                        ->default(fn (): ?string => (array) ($this->record->context_json['identity']['market'] ?? []) !== [] ? (string) ((array) $this->record->context_json['identity']['market'])[0] : null),
                    Select::make('expiry_preset')->label('Hạn sử dụng')->options(IndustryContextExpiry::presets())->default('6_months')->live(),
                    DateTimePicker::make('expires_at_custom')->label('Ngày hết hạn tùy chọn')->visible(fn (Get $get): bool => $get('expiry_preset') === 'custom'),
                ])
                ->action(function (array $data): void {
                    $context = app(IndustryContextGenerationService::class)->generateFromProfile($this->record, $data['notes'] ?? null, $data['market'] ?? null);
                    $expiresAt = IndustryContextExpiry::resolve((string) ($data['expiry_preset'] ?? '6_months'), $data['expires_at_custom'] ?? null);
                    $revision = app(\App\IndustryContext\IndustryContextProfileManager::class)->createRevision($this->record, $context, expiresAt: $expiresAt);
                    $this->redirect(IndustryContextProfileResource::getUrl('view', ['record' => $revision]));
                }),
            Actions\ViewAction::make()->label('Xem'),
            Actions\Action::make('copy_prompt')->label('Copy Prompt')
                ->extraAttributes(function (): array {
                    $identity = (array) ($this->record->context_json['identity'] ?? []);
                    $prompt = app(IndustryContextGenerationService::class)->compilePrompt(
                        (string) ($identity['context_name'] ?? $this->record->name), (string) ($identity['language'] ?? 'en'),
                        implode(', ', array_map('strval', (array) ($identity['market'] ?? []))),
                    );

                    return IndustryContextClipboard::copyAttributes($prompt);
                })
                ->action(fn (): null => null),
            Actions\DeleteAction::make()
                ->action(fn () => \App\Models\IndustryContextProfile::query()->where('key', $this->record->key)->delete()),
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $state = $this->form->getRawState();
        $data['expires_at'] = IndustryContextExpiry::resolve(
            (string) ($state['expiry_preset'] ?? ($this->record->expires_at === null ? 'never' : 'custom')),
            $state['expires_at_custom'] ?? $this->record->expires_at,
        );

        return $data;
    }
}
