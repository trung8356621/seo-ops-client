<?php

namespace App\Filament\Resources\IndustryContextProfileResource\Pages;

use App\Filament\Resources\IndustryContextProfileResource;
use App\Filament\Support\IndustryContextClipboard;
use App\Filament\Support\ValidatesIndustryContextJson;
use App\IndustryContext\IndustryContextExpiry;
use App\IndustryContext\IndustryContextProfileManager;
use App\IndustryContext\IndustryContextSchema;
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

    public ?string $generationPreviewJson = null;

    public ?string $generationPreviewType = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('copy_prompt')
                ->label('Copy Prompt')
                ->extraAttributes(fn (): array => $this->copyPromptAttributes())
                ->action(fn (): null => null),
            Action::make('quick_generate')->label('Gen nhanh')->icon('heroicon-o-sparkles')
                ->modalHeading(fn (): string => $this->generationSeed()['prompt_type'] === 'discovery' ? 'Discovery & Attention JSON' : 'Breakout JSON')
                ->modalHidden(fn (): bool => $this->generationSeed()['prompt_type'] === 'core')
                ->modalContent(fn () => view('filament.components.industry-context-generation-result', [
                    'json' => $this->generationPreviewType === $this->generationSeed()['prompt_type'] ? $this->generationPreviewJson : null,
                ]))
                ->modalSubmitActionLabel('Gen nhanh')
                ->action(function (Action $action): void {
                    $seed = $this->generationSeed();
                    if ($seed['name'] === '') {
                        Notification::make()->title('Vui lòng nhập tên Ngữ cảnh ngành trước.')->warning()->send();

                        return;
                    }
                    $coreContext = $seed['prompt_type'] === 'core' ? null : $this->validCoreContext();
                    if ($seed['prompt_type'] !== 'core' && $coreContext === null) {
                        Notification::make()->title('Vui lòng tạo hoặc nhập Core Industry Context hợp lệ trước.')->warning()->send();
                        $action->halt();

                        return;
                    }
                    $context = app(IndustryContextGenerationService::class)->generateForType(
                        $seed['prompt_type'], $seed['name'], $seed['language'], $seed['market'], $coreContext, $seed['notes'],
                    );
                    if ($seed['prompt_type'] !== 'core') {
                        $this->generationPreviewJson = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $this->generationPreviewType = $seed['prompt_type'];
                        $action->halt();

                        return;
                    }
                    $state = $this->rawFormState();
                    $state['context_json'] = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $this->form->fill($state);
                    Notification::make()->title(__('Generated context filled. Review it before saving.'))->success()->send();
                }),
        ];
    }

    /** @return array{name:string,language:string,market:?string,prompt_type:string,notes:?string} */
    private function generationSeed(): array
    {
        $state = $this->rawFormState();
        $name = trim((string) ($state['name'] ?? ''));
        $language = trim((string) ($state['language'] ?? ''));
        $promptType = (string) ($state['prompt_type'] ?? 'core');
        $market = trim((string) ($state['market'] ?? ''));
        $notes = trim((string) ($state['notes'] ?? ''));

        return [
            'name' => $name,
            'language' => $language !== '' ? $language : 'vi',
            'market' => $market !== '' ? $market : null,
            'prompt_type' => in_array($promptType, ['core', 'discovery', 'breakout'], true) ? $promptType : 'core',
            'notes' => $notes !== '' ? $notes : null,
        ];
    }

    /** @return array<string, string> */
    private function copyPromptAttributes(): array
    {
        if (($message = $this->copyPromptError()) !== null) {
            return IndustryContextClipboard::warningAttributes($message);
        }

        $seed = $this->generationSeed();
        $prompt = app(IndustryContextGenerationService::class)->compilePromptForType(
            $seed['prompt_type'], $seed['name'], $seed['language'], $seed['market'], $seed['prompt_type'] === 'core' ? null : $this->validCoreContext(), $seed['notes'],
        );

        return IndustryContextClipboard::copyAttributes($prompt);
    }

    private function copyPromptError(): ?string
    {
        $seed = $this->generationSeed();
        if ($seed['name'] === '') {
            return 'Vui lòng nhập tên Ngữ cảnh ngành trước.';
        }
        if ($seed['prompt_type'] !== 'core' && $this->validCoreContext() === null) {
            return 'Vui lòng tạo hoặc nhập Core Industry Context hợp lệ trước.';
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function validCoreContext(): ?array
    {
        $value = $this->rawFormState()['context_json'] ?? null;
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) && IndustryContextSchema::validate($value) === [] ? $value : null;
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
