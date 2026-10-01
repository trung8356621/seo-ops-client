<?php

namespace App\Filament\Resources\IndustryContextProfileResource\Pages;

use App\Filament\Resources\IndustryContextProfileResource;
use App\IndustryContext\IndustryContextProfileManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Js;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\IndustryContextGenerationService;

final class CreateIndustryContextProfile extends CreateRecord
{
    protected static string $resource = IndustryContextProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('copy_prompt')->label('Copy Prompt')->action(function (): void {
                $prompt = app(IndustryContextGenerationService::class)->copyPrompt(
                    (string) ($this->data['name'] ?? ''), (string) ($this->data['language'] ?? ''), (string) ($this->data['market'] ?? ''),
                    isset($this->data['notes']) ? (string) $this->data['notes'] : null,
                );
                $this->js('navigator.clipboard.writeText('.Js::from($prompt).')');
            }),
            Action::make('quick_generate')->label('Gen nhanh')->icon('heroicon-o-sparkles')->action(function (): void {
                $context = app(IndustryContextGenerationService::class)->generate(
                    (string) ($this->data['name'] ?? ''), (string) ($this->data['language'] ?? ''),
                    (string) ($this->data['market'] ?? ''), isset($this->data['notes']) ? (string) $this->data['notes'] : null,
                );
                $this->data['context_json'] = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                Notification::make()->title(__('Generated context filled. Review it before saving.'))->success()->send();
            }),
        ];
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        return app(IndustryContextProfileManager::class)->createInitial(
            (string) $data['key'], (string) $data['name'], (array) $data['context_json'],
        );
    }
}
