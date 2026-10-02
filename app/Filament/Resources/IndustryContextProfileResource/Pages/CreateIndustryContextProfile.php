<?php

declare(strict_types=1);

namespace App\Filament\Resources\IndustryContextProfileResource\Pages;

use App\Filament\Resources\IndustryContextProfileResource;
use App\Filament\Support\ValidatesIndustryContextJson;
use App\IndustryContext\IndustryContextExpiry;
use App\IndustryContext\IndustryContextProfileManager;
use App\Models\IndustryContextProfile;
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
            Action::make('download_prompt')->label('Tải Prompt')->icon('heroicon-o-arrow-down-tray')
                ->url(fn (): string => $this->downloadUrl())
                ->openUrlInNewTab()
                ->disabled(fn (): bool => $this->generationSeed()['name'] === ''),
            Action::make('generate_context')->label('Gen Core')->icon('heroicon-o-sparkles')
                ->action(function (): void {
                    $seed = $this->generationSeed();
                    if ($seed['name'] === '') {
                        Notification::make()->title('Vui lòng nhập tên Ngữ cảnh ngành trước.')->warning()->send();

                        return;
                    }
                    $context = app(IndustryContextGenerationService::class)->generateForType(
                        IndustryContextProfile::TYPE_CORE, $seed['name'], $seed['language'], $seed['market'], null, $seed['notes'],
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

    /** @return array<string, mixed> */
    private function rawFormState(): array
    {
        $state = $this->form->getRawState();

        return $state instanceof Arrayable ? $state->toArray() : $state;
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $state = $this->rawFormState();
        $expiresAt = IndustryContextExpiry::resolve((string) ($state['expiry_preset'] ?? '6_months'), $state['expires_at_custom'] ?? null);

        return app(IndustryContextProfileManager::class)
            ->createInitial((string) $data['key'], (string) $data['name'], (array) $data['context_json'], $expiresAt);
    }

    private function downloadUrl(): string
    {
        $seed = $this->generationSeed();
        $parameters = array_filter([
            'language' => $seed['language'], 'market' => $seed['market'], 'notes' => $seed['notes'],
        ], fn (mixed $value): bool => $value !== null);

        return route('admin.industry-context.prompt.create', [...$parameters, 'name' => $seed['name']]);
    }
}
