<?php

namespace App\Filament\Resources\IndustryContextProfileResource\Pages;

use App\Filament\Resources\IndustryContextProfileResource;
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
    protected static string $resource = IndustryContextProfileResource::class;

    public ?string $generationPreviewJson = null;

    public ?string $generationPreviewType = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('copy_prompt')
                ->label('Copy Prompt')
                ->modalHeading('Prompt tạo Industry Context')
                ->modalHidden(fn (): bool => $this->copyPromptError() !== null)
                ->action(function (): void {
                    if (($message = $this->copyPromptError()) !== null) {
                        Notification::make()->title($message)->warning()->send();
                    }
                })
                ->modalContent(fn () => view('filament.components.industry-context-prompt-preview', ['prompt' => $this->compiledPrompt()]))
                ->modalWidth('7xl')->modalSubmitAction(false)->modalCancelActionLabel('Đóng'),
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
                        $seed['prompt_type'], $seed['name'], $seed['language'], $coreContext, $seed['notes'],
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

    /** @return array{name:string,language:string,prompt_type:string,notes:?string} */
    private function generationSeed(): array
    {
        $state = $this->rawFormState();
        $name = trim((string) ($state['name'] ?? ''));
        $language = trim((string) ($state['language'] ?? ''));
        $promptType = (string) ($state['prompt_type'] ?? 'core');
        $notes = trim((string) ($state['notes'] ?? ''));

        return [
            'name' => $name,
            'language' => $language !== '' ? $language : 'vi',
            'prompt_type' => in_array($promptType, ['core', 'discovery', 'breakout'], true) ? $promptType : 'core',
            'notes' => $notes !== '' ? $notes : null,
        ];
    }

    private function compiledPrompt(): string
    {
        $seed = $this->generationSeed();

        return app(IndustryContextGenerationService::class)->compilePromptForType(
            $seed['prompt_type'], $seed['name'], $seed['language'], $seed['prompt_type'] === 'core' ? null : $this->validCoreContext(), $seed['notes'],
        );
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
        );
    }
}
