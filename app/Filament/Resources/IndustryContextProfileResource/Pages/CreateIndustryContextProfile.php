<?php

declare(strict_types=1);

namespace App\Filament\Resources\IndustryContextProfileResource\Pages;

use App\Filament\Resources\IndustryContextProfileResource;
use App\Filament\Support\IndustryContextClipboard;
use App\Filament\Support\ValidatesIndustryContextJson;
use App\IndustryContext\IndustryContextExpiry;
use App\IndustryContext\IndustryContextProfileManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\IndustryContextGenerationService;

final class CreateIndustryContextProfile extends CreateRecord
{
    use ValidatesIndustryContextJson;

    protected static string $resource = IndustryContextProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('copy_prompt')->label('Copy Prompt')
                ->extraAttributes(fn (): array => $this->copyPromptAttributes())
                ->action(fn (): null => null),
            Action::make('generate_core')->label('Gen Core')->icon('heroicon-o-sparkles')
                ->action(function (): void {
                    $seed = $this->generationSeed();
                    if ($seed['name'] === '') {
                        Notification::make()->title('Vui lòng nhập tên Ngữ cảnh ngành trước.')->warning()->send();

                        return;
                    }
                    $context = app(IndustryContextGenerationService::class)->generate(
                        $seed['name'], $seed['language'], $seed['market'], $seed['notes'],
                    );
                    $state = $this->rawFormState();
                    $state['context_json'] = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $this->form->fill($state);
                    Notification::make()->title(__('Generated context filled. Review it before saving.'))->success()->send();
                }),
        ];
    }

    /** @return array{name:string,language:string,market:?string,notes:?string} */
    private function generationSeed(): array
    {
        $state = $this->rawFormState();
        $name = trim((string) ($state['name'] ?? ''));
        $language = trim((string) ($state['language'] ?? ''));
        $market = trim((string) ($state['market'] ?? ''));
        $notes = trim((string) ($state['notes'] ?? ''));

        return [
            'name' => $name,
            'language' => $language !== '' ? $language : 'vi',
            'market' => $market !== '' ? $market : null,
            'notes' => $notes !== '' ? $notes : null,
        ];
    }

    /** @return array<string, string> */
    private function copyPromptAttributes(): array
    {
        $seed = $this->generationSeed();
        if ($seed['name'] === '') {
            return IndustryContextClipboard::warningAttributes('Vui lòng nhập tên Ngữ cảnh ngành trước.');
        }

        $prompt = app(IndustryContextGenerationService::class)->compilePrompt(
            $seed['name'], $seed['language'], $seed['market'], $seed['notes'],
        );

        return IndustryContextClipboard::copyAttributes($prompt);
    }

    /** @return array<string, mixed> */
    private function rawFormState(): array
    {
        $state = $this->form->getRawState();

        return $state instanceof Arrayable ? $state->toArray() : $state;
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        return app(IndustryContextProfileManager::class)->createInitial(
            (string) $data['key'], (string) $data['name'], (array) $data['context_json'],
            IndustryContextExpiry::resolve((string) ($this->rawFormState()['expiry_preset'] ?? '6_months'), $this->rawFormState()['expires_at_custom'] ?? null),
        );
    }
}
