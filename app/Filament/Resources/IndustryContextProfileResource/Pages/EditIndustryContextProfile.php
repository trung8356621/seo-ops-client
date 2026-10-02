<?php

declare(strict_types=1);

namespace App\Filament\Resources\IndustryContextProfileResource\Pages;

use App\Filament\Resources\IndustryContextProfileResource;
use App\Filament\Support\ValidatesIndustryContextJson;
use App\IndustryContext\IndustryContextExpiry;
use App\IndustryContext\IndustryContextProfileManager;
use App\Models\IndustryContextProfile;
use Filament\Actions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Collection;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\IndustryContextGenerationService;

final class EditIndustryContextProfile extends EditRecord
{
    use ValidatesIndustryContextJson;

    protected static string $resource = IndustryContextProfileResource::class;

    protected static string $view = 'filament.resources.industry-context-profile-resource.pages.edit-industry-context-profile';

    public string $selectedType = IndustryContextProfile::TYPE_CORE;

    public int $workspaceCoreId;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->selectedType = $this->record->type;
        $core = $this->manager()->active((string) $this->record->key, IndustryContextProfile::TYPE_CORE)
            ?? IndustryContextProfile::query()->where('key', $this->record->key)->where('type', IndustryContextProfile::TYPE_CORE)->latest('id')->firstOrFail();
        $this->workspaceCoreId = (int) $core->getKey();
    }

    public function selectType(string $type): void
    {
        abort_unless(in_array($type, [IndustryContextProfile::TYPE_CORE, IndustryContextProfile::TYPE_DISCOVERY, IndustryContextProfile::TYPE_BREAKOUT, IndustryContextProfile::TYPE_MATCH], true), 404);
        $this->selectedType = $type;
        $branch = $this->manager()->active($this->workspaceCore()->key, $type) ?? $this->manager()->revisions($this->workspaceCore()->key, $type)->first();
        if ($branch !== null) {
            $this->record = $branch;
            $this->fillForm();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('generate_revision')->label(fn (): string => match ($this->selectedType) {
                IndustryContextProfile::TYPE_DISCOVERY => 'Gen Knowledge & Search',
                IndustryContextProfile::TYPE_BREAKOUT => 'Gen Lifestyle & Usage',
                IndustryContextProfile::TYPE_MATCH => 'Gen Match & Research',
                default => 'Gen revision',
            })->icon('heroicon-o-sparkles')
                ->form([
                    Textarea::make('notes')->label(__('Temporary notes')),
                    Select::make('market')->label('Thị trường mục tiêu')->options(\App\IndustryContext\IndustryMarketOptions::options())->searchable()
                        ->default(fn (): ?string => $this->market()),
                    Select::make('expiry_preset')->label('Hạn sử dụng')->options(IndustryContextExpiry::presets())
                        ->default(fn (): string => $this->selectedType === IndustryContextProfile::TYPE_MATCH ? 'never' : '6_months')->live(),
                    DateTimePicker::make('expires_at_custom')->label('Ngày hết hạn tùy chọn')->visible(fn (Get $get): bool => $get('expiry_preset') === 'custom'),
                ])
                ->action(fn (array $data) => $this->generateRevision($data)),
            Actions\DeleteAction::make()->action(function (): void {
                IndustryContextProfile::query()->where('key', $this->workspaceCore()->key)->delete();
                $this->redirect(IndustryContextProfileResource::getUrl('index'));
            }),
        ];
    }

    /** @param array<string, mixed> $data */
    public function generateRevision(array $data): void
    {
        $core = $this->manager()->active($this->workspaceCore()->key, IndustryContextProfile::TYPE_CORE) ?? $this->workspaceCore();
        $service = app(IndustryContextGenerationService::class);
        $context = $this->selectedType === IndustryContextProfile::TYPE_CORE
            ? $service->generateFromProfile($core, $data['notes'] ?? null, $data['market'] ?? null)
            : $service->generateForType($this->selectedType, $core->name, $this->language(), $data['market'] ?? $this->market(), (array) $core->context_json, $data['notes'] ?? null);
        $defaultExpiry = $this->selectedType === IndustryContextProfile::TYPE_MATCH ? 'never' : '6_months';
        $expiresAt = IndustryContextExpiry::resolve((string) ($data['expiry_preset'] ?? $defaultExpiry), $data['expires_at_custom'] ?? null);
        $revision = $this->selectedType === IndustryContextProfile::TYPE_CORE
            ? $this->manager()->createRevision($core, $context, expiresAt: $expiresAt)
            : $this->manager()->createAuxiliaryRevision($core->key, $this->selectedType, $context, $expiresAt);
        $this->record = $revision;
        $this->fillForm();
        Notification::make()->title('Đã tạo revision để xem lại. Chọn “Dùng bản này” khi sẵn sàng.')->success()->send();
    }

    public function activateRevision(int $id): void
    {
        $profile = IndustryContextProfile::query()->where('key', $this->workspaceCore()->key)->where('type', $this->selectedType)->findOrFail($id);
        $this->record = $this->manager()->activate($profile);
        $this->fillForm();
        Notification::make()->title('Đã dùng bản này')->success()->send();
    }

    public function workspaceCore(): IndustryContextProfile
    {
        return IndustryContextProfile::query()->findOrFail($this->workspaceCoreId);
    }

    public function branch(): ?IndustryContextProfile
    {
        if ($this->record->type === $this->selectedType) {
            return $this->record;
        }

        return $this->manager()->active($this->workspaceCore()->key, $this->selectedType) ?? $this->manager()->revisions($this->workspaceCore()->key, $this->selectedType)->first();
    }

    /** @return Collection<int, IndustryContextProfile> */
    public function revisions(): Collection
    {
        return $this->manager()->revisions($this->workspaceCore()->key, $this->selectedType);
    }

    public function isStale(IndustryContextProfile $profile): bool
    {
        return $this->manager()->isStale($profile);
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

    protected function getRedirectUrl(): string
    {
        return IndustryContextProfileResource::getUrl('edit', ['record' => $this->workspaceCoreId]);
    }

    private function language(): string
    {
        $identity = (array) ($this->workspaceCore()->context_json['identity'] ?? []);

        return (string) ($identity['language'] ?? 'en');
    }

    private function market(): ?string
    {
        $identity = (array) ($this->workspaceCore()->context_json['identity'] ?? []);
        $market = implode(', ', array_map('strval', (array) ($identity['market'] ?? [])));

        return $market !== '' ? $market : null;
    }

    private function manager(): IndustryContextProfileManager
    {
        return app(IndustryContextProfileManager::class);
    }
}
