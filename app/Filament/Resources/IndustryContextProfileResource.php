<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Forms\Components\JsonCodeEditor;
use App\Filament\Resources\IndustryContextProfileResource\Pages;
use App\Filament\Support\IndustryContextClipboard;
use App\IndustryContext\IndustryContextSchema;
use App\Models\IndustryContextProfile;
use App\Models\User;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use JsonException;

final class IndustryContextProfileResource extends Resource
{
    protected static ?string $model = IndustryContextProfile::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-asia-australia';

    protected static ?int $navigationSort = 35;

    protected static ?string $slug = 'industry-context-profiles';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.system');
    }

    public static function getNavigationLabel(): string
    {
        return __('Industry Contexts');
    }

    public static function getModelLabel(): string
    {
        return __('Industry Context');
    }

    public static function canAccess(): bool
    {
        return in_array((string) (auth()->user()?->role ?? ''), [User::ROLE_OWNER, User::ROLE_ADMIN], true);
    }

    public static function canEdit(Model $record): bool
    {
        return self::canAccess();
    }

    public static function canDelete(Model $record): bool
    {
        return self::canAccess();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->required()->maxLength(255)->live(debounce: 300),
            Forms\Components\TextInput::make('key')
                ->required()->maxLength(255)
                ->readOnly(fn (?IndustryContextProfile $record): bool => $record !== null)
                ->rule(function (?IndustryContextProfile $record): Closure {
                    return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        if ($record === null && IndustryContextProfile::query()->where('key', (string) $value)->exists()) {
                            $fail(__('This Industry Context key already exists.'));
                        }
                    };
                })
                ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                ->helperText(__('Stable Industry Context key used by Sites.')),
            Forms\Components\TextInput::make('schema_version')
                ->default(IndustryContextSchema::VERSION)->readOnly()->dehydrated(),
            Forms\Components\TextInput::make('language')
                ->label(__('Language'))->default('vi')
                ->visible(fn (?IndustryContextProfile $record): bool => $record === null)->dehydrated(false)->live(debounce: 300),
            Forms\Components\TextInput::make('market')
                ->label(__('Market'))
                ->visible(fn (?IndustryContextProfile $record): bool => $record === null)->dehydrated(false)->live(debounce: 300),
            Forms\Components\Textarea::make('notes')
                ->label(__('Temporary generation notes'))->rows(3)
                ->visible(fn (?IndustryContextProfile $record): bool => $record === null)->dehydrated(false)->live(debounce: 300),
            JsonCodeEditor::make('context_json')
                ->label(__('Context JSON'))
                ->rows(30)->columnSpanFull()->required()
                ->formatStateUsing(fn (mixed $state): string => is_array($state)
                    ? (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : (string) $state)
                ->rules([
                    function (): Closure {
                        return function (string $attribute, mixed $value, Closure $fail): void {
                            try {
                                $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
                            } catch (JsonException $exception) {
                                $fail(__('Invalid JSON: :message', ['message' => $exception->getMessage()]));

                                return;
                            }
                            $errors = IndustryContextSchema::validate($decoded);
                            if ($errors !== []) {
                                $fail(implode(' ', $errors));
                            }
                        };
                    },
                ])
                ->dehydrateStateUsing(fn (string $state): array => json_decode($state, true, 512, JSON_THROW_ON_ERROR)),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('key')->searchable()->copyable(),
            Tables\Columns\TextColumn::make('schema_version')->label(__('Schema')),
            Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable(),
        ])->actions([
            Tables\Actions\Action::make('quick_generate')
                ->label('Gen nhanh')->icon('heroicon-o-sparkles')
                ->form([Forms\Components\Textarea::make('notes')->label(__('Temporary notes'))])
                ->action(function (IndustryContextProfile $record, array $data, $livewire): void {
                    $context = app(\Omnichannel\Addons\AiPrompt\Services\PromptOwnership\IndustryContextGenerationService::class)
                        ->generateFromProfile($record, $data['notes'] ?? null);
                    $revision = app(\App\IndustryContext\IndustryContextProfileManager::class)->createRevision($record, $context);
                    $livewire->redirect(self::getUrl('view', ['record' => $revision]));
                }),
            Tables\Actions\ViewAction::make()->label('Xem'),
            Tables\Actions\Action::make('copy_prompt')
                ->label('Copy Prompt')->icon('heroicon-o-clipboard')
                ->extraAttributes(function (IndustryContextProfile $record): array {
                    $identity = (array) ($record->context_json['identity'] ?? []);
                    $prompt = app(\Omnichannel\Addons\AiPrompt\Services\PromptOwnership\IndustryContextGenerationService::class)->compilePrompt(
                        (string) ($identity['context_name'] ?? $record->name),
                        (string) ($identity['language'] ?? 'en'),
                        implode(', ', array_map('strval', (array) ($identity['market'] ?? []))),
                    );

                    return ['x-on:click' => IndustryContextClipboard::copyScript($prompt)];
                })
                ->action(fn (): null => null),
            Tables\Actions\EditAction::make()->label('Sửa'),
            Tables\Actions\DeleteAction::make()
                ->action(fn (IndustryContextProfile $record) => IndustryContextProfile::query()->where('key', $record->key)->delete()),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->logicalRepresentatives();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIndustryContextProfiles::route('/'),
            'create' => Pages\CreateIndustryContextProfile::route('/create'),
            'edit' => Pages\EditIndustryContextProfile::route('/{record}/edit'),
            'view' => Pages\ViewIndustryContextProfile::route('/{record}'),
        ];
    }
}
