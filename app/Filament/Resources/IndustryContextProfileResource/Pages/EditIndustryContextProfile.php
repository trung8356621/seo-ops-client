<?php

declare(strict_types=1);

namespace App\Filament\Resources\IndustryContextProfileResource\Pages;

use App\Filament\Resources\IndustryContextProfileResource;
use App\Filament\Support\ValidatesIndustryContextJson;
use App\IndustryContext\ActiveIndustryMatchRuleProvider;
use App\IndustryContext\IndustryAuxiliarySchema;
use App\IndustryContext\IndustryContextExpiry;
use App\IndustryContext\IndustryContextProfileManager;
use App\IndustryContext\IndustryContextSchema;
use App\Models\IndustryContextProfile;
use Filament\Actions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Collection;
use JsonException;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\IndustryContextGenerationService;
use Omnichannel\Addons\SearchFoundation\Enums\IndustryGroupType;
use Omnichannel\Addons\SearchFoundation\Services\MatchResearch\IndustryMatchResearchProjector;
use Omnichannel\Addons\SearchIntelligence\Filament\Pages\SeoSettingsKeywords;
use Throwable;

final class EditIndustryContextProfile extends EditRecord
{
    use ValidatesIndustryContextJson;

    protected static string $resource = IndustryContextProfileResource::class;

    protected static string $view = 'filament.resources.industry-context-profile-resource.pages.edit-industry-context-profile';

    public const ALLOWED_TYPES = [
        IndustryContextProfile::TYPE_CORE,
        IndustryContextProfile::TYPE_DISCOVERY,
        IndustryContextProfile::TYPE_BREAKOUT,
        IndustryContextProfile::TYPE_MATCH,
    ];

    public string $selectedType = IndustryContextProfile::TYPE_CORE;

    public int $workspaceCoreId;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $core = $this->manager()->active((string) $this->record->key, IndustryContextProfile::TYPE_CORE)
            ?? IndustryContextProfile::query()->where('key', $this->record->key)->where('type', IndustryContextProfile::TYPE_CORE)->latest('id')->firstOrFail();
        $this->workspaceCoreId = (int) $core->getKey();

        $this->selectedType = $this->resolveSelectedType();

        $revisions = $this->manager()->revisions($core->key, $this->selectedType);
        $branch = $this->resolveBranchRevision($revisions, request()->query('rev'));

        if ($branch !== null) {
            $this->record = $branch;
            $this->fillFormForBranch($branch);
        } else {
            $this->record = $core;
            $this->fillFormForBranch(null);
        }
    }

    public function resolveSelectedType(?string $queryType = null): string
    {
        $queryType ??= request()->query('type');

        if (is_string($queryType) && in_array($queryType, self::ALLOWED_TYPES, true)) {
            return $queryType;
        }

        if (isset($this->record) && in_array((string) $this->record->type, self::ALLOWED_TYPES, true)) {
            return (string) $this->record->type;
        }

        return IndustryContextProfile::TYPE_CORE;
    }

    /** @param Collection<int, IndustryContextProfile> $revisions */
    public function resolveBranchRevision(Collection $revisions, mixed $requestedRev = null): ?IndustryContextProfile
    {
        if ($revisions->isEmpty()) {
            return null;
        }

        $latest = $revisions->first();

        if (filled($requestedRev) && is_numeric($requestedRev)) {
            $revId = (int) $requestedRev;
            $matched = $revisions->firstWhere('id', $revId);
            if ($matched !== null) {
                return $matched;
            }

            $dbMatched = IndustryContextProfile::query()
                ->where('key', $this->workspaceCore()->key)
                ->where('type', $this->selectedType)
                ->find($revId);

            if ($dbMatched !== null) {
                return $dbMatched;
            }
        }

        return $latest;
    }

    public function tabUrl(string $type): string
    {
        return IndustryContextProfileResource::getUrl('edit', [
            'record' => $this->workspaceCoreId,
            'type' => $type,
        ]);
    }

    public function revisionUrl(string $type, int $revId): string
    {
        return IndustryContextProfileResource::getUrl('edit', [
            'record' => $this->workspaceCoreId,
            'type' => $type,
            'rev' => $revId,
        ]);
    }

    public function fillFormForBranch(?IndustryContextProfile $branch): void
    {
        if ($branch !== null) {
            $this->record = $branch;
            $this->form->fill([
                'context_json' => is_array($branch->context_json)
                    ? json_encode($branch->context_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : (string) $branch->context_json,
                'expiry_preset' => $branch->expires_at === null ? 'never' : 'custom',
                'expires_at_custom' => $branch->expires_at?->format('Y-m-d H:i:s'),
            ]);
        } else {
            $defaultPreset = $this->selectedType === IndustryContextProfile::TYPE_MATCH ? 'never' : '6_months';
            $this->form->fill([
                'context_json' => json_encode($this->canonicalTemplate($this->selectedType), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'expiry_preset' => $defaultPreset,
                'expires_at_custom' => null,
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function canonicalTemplate(string $type): array
    {
        if ($type === IndustryContextProfile::TYPE_CORE) {
            $core = $this->workspaceCore();
            $identity = (array) ($core->context_json['identity'] ?? []);

            return IndustryContextSchema::template(
                $core->name,
                $core->key,
                (string) ($identity['language'] ?? 'vi'),
                (array) ($identity['market'] ?? ['VN']),
            );
        }

        return IndustryAuxiliarySchema::template($type);
    }

    public function handleExpiryPresetUpdated(?string $preset): void
    {
        $branch = $this->branch();
        if ($branch === null) {
            return;
        }

        if ($preset === 'custom') {
            $custom = $this->form->getRawState()['expires_at_custom'] ?? null;
            if (filled($custom)) {
                $expiresAt = IndustryContextExpiry::resolve('custom', $custom);
                $branch->forceFill(['expires_at' => $expiresAt])->save();
                $this->record = $branch->refresh();
                Notification::make()->title('Đã cập nhật hạn sử dụng')->success()->send();
            }

            return;
        }

        if ($preset !== null) {
            $expiresAt = IndustryContextExpiry::resolve($preset);
            $branch->forceFill(['expires_at' => $expiresAt])->save();
            $this->record = $branch->refresh();
            Notification::make()->title('Đã cập nhật hạn sử dụng')->success()->send();
        }
    }

    public function handleExpiresAtCustomUpdated(mixed $custom): void
    {
        $branch = $this->branch();
        if ($branch === null) {
            return;
        }

        $preset = (string) ($this->form->getRawState()['expiry_preset'] ?? 'custom');
        if ($preset === 'custom' && filled($custom)) {
            $expiresAt = IndustryContextExpiry::resolve('custom', $custom);
            $branch->forceFill(['expires_at' => $expiresAt])->save();
            $this->record = $branch->refresh();
            Notification::make()->title('Đã cập nhật hạn sử dụng')->success()->send();
        }
    }

    public function saveManualRevision(): void
    {
        $state = $this->form->getRawState();
        $rawJson = $state['context_json'] ?? '';

        if (is_array($rawJson)) {
            $decoded = $rawJson;
        } else {
            $rawString = trim((string) $rawJson);
            if ($rawString === '') {
                Notification::make()->title('Vui lòng nhập hoặc dán Context JSON.')->danger()->send();

                return;
            }

            try {
                $decoded = json_decode($rawString, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                Notification::make()->title('JSON không hợp lệ: '.$exception->getMessage())->danger()->send();

                return;
            }
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            Notification::make()->title('Context JSON phải là một JSON object.')->danger()->send();

            return;
        }

        if ($this->selectedType === IndustryContextProfile::TYPE_CORE) {
            $errors = IndustryContextSchema::validate($decoded);
            if ($errors !== []) {
                Notification::make()->title('JSON đúng cú pháp nhưng không đúng Industry Context Schema: '.implode(' ', $errors))->danger()->send();

                return;
            }
        } else {
            try {
                $decoded = IndustryAuxiliarySchema::validatedOutput($this->selectedType, $decoded);
            } catch (Throwable $exception) {
                Notification::make()->title('JSON đúng cú pháp nhưng không đúng Industry Context Schema: '.$exception->getMessage())->danger()->send();

                return;
            }
        }

        $defaultExpiry = $this->selectedType === IndustryContextProfile::TYPE_MATCH ? 'never' : '6_months';
        $preset = (string) ($state['expiry_preset'] ?? $defaultExpiry);
        $custom = $state['expires_at_custom'] ?? null;
        $expiresAt = IndustryContextExpiry::resolve($preset, $custom);

        $core = $this->manager()->active($this->workspaceCore()->key, IndustryContextProfile::TYPE_CORE) ?? $this->workspaceCore();

        $latestRevision = $this->manager()->revisions($core->key, $this->selectedType)->first();
        if ($latestRevision !== null) {
            $fingerprint = app(\App\IndustryContext\IndustryContextFingerprint::class);
            $newHash = $fingerprint->hash($decoded);
            $latestHash = $fingerprint->hash((array) $latestRevision->context_json);

            if (hash_equals($latestHash, $newHash)) {
                Notification::make()->title('Không có thay đổi')->info()->send();

                return;
            }
        }

        $revision = $this->selectedType === IndustryContextProfile::TYPE_CORE
            ? $this->manager()->createRevision($core, $decoded, expiresAt: $expiresAt)
            : $this->manager()->createAuxiliaryRevision($core->key, $this->selectedType, $decoded, $expiresAt);

        if ($this->selectedType === IndustryContextProfile::TYPE_CORE) {
            $this->workspaceCoreId = (int) ($this->manager()->active((string) $revision->key, IndustryContextProfile::TYPE_CORE)?->getKey() ?? $revision->getKey());
        }

        $this->record = $revision;
        $this->fillFormForBranch($revision);

        Notification::make()->title('Đã lưu thành revision mới')->success()->send();

        $this->redirect($this->revisionUrl($this->selectedType, (int) $revision->id));
    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        $this->saveManualRevision();
    }

    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
        ];
    }

    protected function getSaveFormAction(): Actions\Action
    {
        return Actions\Action::make('save')
            ->label('Lưu')
            ->action('saveManualRevision')
            ->keyBindings(['mod+s']);
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
        $this->fillFormForBranch($revision);
        Notification::make()->title('Đã tạo revision để xem lại. Chọn “Dùng bản này” khi sẵn sàng.')->success()->send();
    }

    public function activateRevision(int $id): void
    {
        $profile = IndustryContextProfile::query()->where('key', $this->workspaceCore()->key)->where('type', $this->selectedType)->findOrFail($id);
        $this->record = $this->manager()->activate($profile);
        $this->fillFormForBranch($this->record);
        Notification::make()->title('Đã dùng bản này')->success()->send();
    }

    public function activeMatchRevision(): ?IndustryContextProfile
    {
        return $this->manager()->active($this->workspaceCore()->key, IndustryContextProfile::TYPE_MATCH);
    }

    public function editorIsDirty(): bool
    {
        $state = $this->form->getRawState();
        $raw = $state['context_json'] ?? '';
        $current = is_array($raw)
            ? json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : trim((string) $raw);
        $branch = $this->branch();
        $saved = $branch === null
            ? json_encode($this->canonicalTemplate($this->selectedType), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (is_array($branch->context_json)
                ? json_encode($branch->context_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string) $branch->context_json);

        return trim((string) $current) !== trim((string) $saved);
    }

    public function selectRevision(int $id): void
    {
        if ($this->editorIsDirty()) {
            Notification::make()->title('JSON chưa lưu. Lưu revision trước khi đổi bản xem.')->warning()->send();

            return;
        }

        $this->redirect($this->revisionUrl($this->selectedType, $id));
    }

    public function selectType(string $type): void
    {
        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            return;
        }
        if ($this->editorIsDirty()) {
            Notification::make()->title('JSON chưa lưu. Lưu revision trước khi đổi tab.')->warning()->send();

            return;
        }

        $this->redirect($this->tabUrl($type));
    }

    public function liveIndustryMatchUrl(): string
    {
        return SeoSettingsKeywords::getUrl();
    }

    /**
     * Read-only projection of the selected Match revision. Runtime stays on the active revision.
     *
     * @return array{groups: array<string, list<array<string, mixed>>>, secondary: array<string, list<array<string, mixed>>>}
     */
    public function matchResearchView(): array
    {
        $empty = ['groups' => [], 'secondary' => []];
        if ($this->selectedType !== IndustryContextProfile::TYPE_MATCH) {
            return $empty;
        }
        $branch = $this->branch();
        if ($branch === null) {
            return $empty;
        }

        $resources = app(IndustryMatchResearchProjector::class)->projectRules(
            ActiveIndustryMatchRuleProvider::rulesFromContext((array) $branch->context_json),
            [
                'industry_context_key' => $branch->key,
                'match_revision_id' => (int) $branch->getKey(),
            ],
            $this->language(),
        );

        $groups = [];
        $secondary = [];
        foreach ($resources as $resource) {
            $group = (string) ($resource->payload['group'] ?? '');
            $row = [
                'canonical' => $resource->label,
                'aliases' => array_values(array_map('strval', (array) ($resource->payload['aliases'] ?? []))),
                'match_mode' => $resource->matchMode,
                'group_type' => $group,
                'enabled' => $resource->payload['enabled'] ?? null,
                'kind' => $resource->kind->value,
                'do_not_confuse_with' => array_values(array_map('strval', (array) ($resource->payload['do_not_confuse_with'] ?? []))),
            ];
            if (in_array($group, IndustryGroupType::values(), true)) {
                $groups[$group][] = $row;
            } else {
                $secondary[$group][] = $row;
            }
        }

        return ['groups' => $groups, 'secondary' => $secondary];
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
        return IndustryContextProfileResource::getUrl('edit', [
            'record' => $this->workspaceCoreId,
            'type' => $this->selectedType,
        ]);
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
