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
            Action::make('generate_context')->label(fn (): string => match ($this->generationSeed()['type']) {
                IndustryContextProfile::TYPE_DISCOVERY => 'Gen Discovery',
                IndustryContextProfile::TYPE_BREAKOUT => 'Gen Breakout',
                default => 'Gen Core',
            })->icon('heroicon-o-sparkles')
                ->action(function (): void {
                    $seed = $this->generationSeed();
                    if ($seed['name'] === '') {
                        Notification::make()->title('Vui lòng nhập tên Ngữ cảnh ngành trước.')->warning()->send();

                        return;
                    }
                    $core = $seed['group'] === '__new__' ? null : $this->activeCore($seed['group']);
                    if ($seed['type'] !== IndustryContextProfile::TYPE_CORE && $core === null) {
                        Notification::make()->title('Industry Context đã chọn không có Core đang hoạt động.')->warning()->send();

                        return;
                    }
                    $context = app(IndustryContextGenerationService::class)->generateForType(
                        $seed['type'], $seed['name'], $seed['language'], $seed['market'], $core?->context_json, $seed['notes'],
                    );
                    $state = $this->rawFormState();
                    $state['context_json'] = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $this->form->fill($state);
                    Notification::make()->title(__('Generated context filled. Review it before saving.'))->success()->send();
                }),
        ];
    }

    /** @return array{group:string,type:string,name:string,language:string,market:?string,notes:?string} */
    private function generationSeed(): array
    {
        $state = $this->rawFormState();
        $name = trim((string) ($state['name'] ?? ''));
        $language = trim((string) ($state['language'] ?? ''));
        $market = trim((string) ($state['market'] ?? ''));
        $notes = trim((string) ($state['notes'] ?? ''));
        $group = (string) ($state['group_selector'] ?? '__new__');
        $type = (string) ($state['type'] ?? IndustryContextProfile::TYPE_CORE);

        return [
            'group' => $group !== '' ? $group : '__new__',
            'type' => in_array($type, [IndustryContextProfile::TYPE_CORE, IndustryContextProfile::TYPE_DISCOVERY, IndustryContextProfile::TYPE_BREAKOUT], true) ? $type : IndustryContextProfile::TYPE_CORE,
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
        $group = (string) ($state['group_selector'] ?? '__new__');
        $type = (string) ($state['type'] ?? IndustryContextProfile::TYPE_CORE);
        $expiresAt = IndustryContextExpiry::resolve((string) ($state['expiry_preset'] ?? '6_months'), $state['expires_at_custom'] ?? null);
        $manager = app(IndustryContextProfileManager::class);

        if ($group === '__new__') {
            abort_unless($type === IndustryContextProfile::TYPE_CORE, 422, 'Một Industry Context mới phải bắt đầu bằng Core.');

            return $manager->createInitial((string) $data['key'], (string) $data['name'], (array) $data['context_json'], $expiresAt);
        }

        $core = $type === IndustryContextProfile::TYPE_CORE
            ? $this->coreRepresentative($group)
            : $this->activeCore($group);
        abort_if($core === null, 422, 'Industry Context đã chọn không có Core đang hoạt động.');

        return $type === IndustryContextProfile::TYPE_CORE
            ? $manager->createRevision($core, (array) $data['context_json'], expiresAt: $expiresAt)
            : $manager->createAuxiliaryRevision($group, $type, (array) $data['context_json'], $expiresAt);
    }

    private function downloadUrl(): string
    {
        $seed = $this->generationSeed();
        $parameters = array_filter([
            'language' => $seed['language'], 'market' => $seed['market'], 'notes' => $seed['notes'],
        ], fn (mixed $value): bool => $value !== null);

        return $seed['group'] === '__new__'
            ? route('admin.industry-context.prompt.create', [...$parameters, 'name' => $seed['name']])
            : route('admin.industry-context.prompt.download', [...$parameters, 'key' => $seed['group'], 'type' => $seed['type']]);
    }

    private function activeCore(string $key): ?IndustryContextProfile
    {
        return app(IndustryContextProfileManager::class)->active($key, IndustryContextProfile::TYPE_CORE);
    }

    private function coreRepresentative(string $key): ?IndustryContextProfile
    {
        return $this->activeCore($key)
            ?? IndustryContextProfile::query()->where('key', $key)->where('type', IndustryContextProfile::TYPE_CORE)->latest('id')->first();
    }
}
