<?php

namespace App\Filament\Resources\IndustryContextProfileResource\Pages;

use App\Filament\Resources\IndustryContextProfileResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\IndustryContextGenerationService;

final class EditIndustryContextProfile extends EditRecord
{
    protected static string $resource = IndustryContextProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('quick_generate')->label('Gen nhanh')->icon('heroicon-o-sparkles')
                ->form([\Filament\Forms\Components\Textarea::make('notes')->label(__('Temporary notes'))])
                ->action(function (array $data): void {
                    $context = app(IndustryContextGenerationService::class)->generateFromProfile($this->record, $data['notes'] ?? null);
                    $revision = app(\App\IndustryContext\IndustryContextProfileManager::class)->createRevision($this->record, $context);
                    $this->redirect(IndustryContextProfileResource::getUrl('view', ['record' => $revision]));
                }),
            Actions\ViewAction::make()->label('Xem'),
            Actions\Action::make('copy_prompt')->label('Copy Prompt')
                ->modalHeading('Prompt tạo Industry Context')
                ->modalContent(function () {
                    $identity = (array) ($this->record->context_json['identity'] ?? []);
                    $prompt = app(IndustryContextGenerationService::class)->compilePrompt(
                        (string) ($identity['context_name'] ?? $this->record->name), (string) ($identity['language'] ?? 'en'),
                    );

                    return view('filament.components.industry-context-prompt-preview', ['prompt' => $prompt]);
                })
                ->modalWidth('7xl')->modalSubmitAction(false)->modalCancelActionLabel('Đóng'),
            Actions\DeleteAction::make()
                ->action(fn () => \App\Models\IndustryContextProfile::query()->where('key', $this->record->key)->delete()),
        ];
    }
}
