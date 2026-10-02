<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Forms\Components\JsonCodeEditor;
use App\Filament\Resources\IndustryContextProfileResource\Pages;
use App\IndustryContext\IndustryAuxiliarySchema;
use App\IndustryContext\IndustryContextExpiry;
use App\IndustryContext\IndustryContextProfileManager;
use App\IndustryContext\IndustryContextSchema;
use App\IndustryContext\IndustryMarketOptions;
use App\Models\IndustryContextProfile;
use App\Models\User;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

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
            Forms\Components\Select::make('group_selector')->label('Industry Context')
                ->options(fn (): array => [
                    '__new__' => '+ Tạo mới',
                    ...IndustryContextProfile::query()->logicalRepresentatives()->get()
                        ->mapWithKeys(fn (IndustryContextProfile $profile): array => [$profile->key => $profile->name.' — '.$profile->key])
                        ->all(),
                ])
                ->default('__new__')->searchable()->live()->dehydrated(false)
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    $set('type', IndustryContextProfile::TYPE_CORE);
                    $set('context_json', null);
                    if ($state === null || $state === '__new__') {
                        $set('name', null);
                        $set('key', null);

                        return;
                    }
                    $core = app(IndustryContextProfileManager::class)->active($state, IndustryContextProfile::TYPE_CORE)
                        ?? IndustryContextProfile::query()->where('key', $state)->where('type', IndustryContextProfile::TYPE_CORE)->latest('id')->first();
                    $set('name', $core?->name);
                    $set('key', $core?->key);
                    $identity = (array) ($core?->context_json['identity'] ?? []);
                    $set('language', (string) ($identity['language'] ?? 'vi'));
                    $market = (array) ($identity['market'] ?? []);
                    $set('market', $market === [] ? null : (string) $market[0]);
                })
                ->visible(fn (?IndustryContextProfile $record): bool => $record === null),
            Forms\Components\TextInput::make('name')->required()->maxLength(255)->live(debounce: 300)
                ->afterStateUpdated(function (?string $state, Set $set, Get $get, ?IndustryContextProfile $record): void {
                    if ($record === null && $get('group_selector') === '__new__') {
                        $set('key', self::keyFromName((string) $state));
                    }
                })
                ->readOnly(fn (Get $get): bool => ! in_array($get('group_selector'), [null, '', '__new__'], true))
                ->visible(fn (?IndustryContextProfile $record): bool => $record === null),
            Forms\Components\TextInput::make('key')->required()->maxLength(255)->readOnly()->dehydrated()
                ->rule(fn (Get $get, ?IndustryContextProfile $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                    if ($record === null && $get('group_selector') === '__new__' && IndustryContextProfile::query()->where('key', (string) $value)->exists()) {
                        $fail('Industry Context này có thể đã tồn tại. Key: '.(string) $value);
                    }
                })
                ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                ->helperText(fn (Get $get): string => $get('group_selector') === '__new__' && IndustryContextProfile::query()->where('key', (string) $get('key'))->exists()
                    ? 'Industry Context này có thể đã tồn tại. Mở Industry Context hiện có từ danh sách.'
                    : 'Tự tạo từ Name và không đổi sau khi lưu.')
                ->visible(fn (?IndustryContextProfile $record): bool => $record === null),
            Forms\Components\Select::make('type')->label('Type')
                ->options(function (Get $get): array {
                    $options = [IndustryContextProfile::TYPE_CORE => 'Core'];
                    $key = (string) $get('group_selector');
                    if ($key !== '' && $key !== '__new__' && app(IndustryContextProfileManager::class)->active($key, IndustryContextProfile::TYPE_CORE) !== null) {
                        $options[IndustryContextProfile::TYPE_DISCOVERY] = 'Discovery & Attention';
                        $options[IndustryContextProfile::TYPE_BREAKOUT] = 'Breakout';
                    }

                    return $options;
                })
                ->default(IndustryContextProfile::TYPE_CORE)->required()->live()->dehydrated(false)
                ->afterStateUpdated(fn (Set $set) => $set('context_json', null))
                ->visible(fn (?IndustryContextProfile $record): bool => $record === null),
            Forms\Components\TextInput::make('schema_version')->default(IndustryContextSchema::VERSION)->readOnly()->dehydrated()->hidden(),
            Forms\Components\Select::make('language')->label(__('Language'))->options(self::languageOptions())
                ->default(fn (): string => self::defaultLanguage(request()->query('defaultLanguage')))
                ->visible(fn (?IndustryContextProfile $record): bool => $record === null)->dehydrated(false)->live(debounce: 300),
            Forms\Components\Select::make('market')->label('Thị trường mục tiêu')->options(IndustryMarketOptions::options())->searchable()
                ->default(fn (): ?string => IndustryMarketOptions::default(request()->query('defaultMarket'), self::defaultLanguage(request()->query('defaultLanguage'))))
                ->visible(fn (?IndustryContextProfile $record): bool => $record === null)->dehydrated(false)->live(debounce: 300),
            Forms\Components\Textarea::make('notes')->label(__('Temporary generation notes'))->rows(3)
                ->visible(fn (?IndustryContextProfile $record): bool => $record === null)->dehydrated(false)->live(debounce: 300),
            Forms\Components\Select::make('expiry_preset')->label('Hạn sử dụng')->options(IndustryContextExpiry::presets())
                ->default(fn (?IndustryContextProfile $record): string => $record === null ? '6_months' : ($record->expires_at === null ? 'never' : 'custom'))
                ->dehydrated(false)->live(),
            Forms\Components\DateTimePicker::make('expires_at_custom')->label('Ngày hết hạn tùy chọn')
                ->default(fn (?IndustryContextProfile $record) => $record?->expires_at)
                ->visible(fn (Get $get): bool => $get('expiry_preset') === 'custom')->dehydrated(false),
            JsonCodeEditor::make('context_json')->label(__('Context JSON'))->rows(30)->columnSpanFull()->required()
                ->schemaType(fn (Get $get, ?IndustryContextProfile $record): string => $record?->type ?? (string) ($get('type') ?: IndustryContextProfile::TYPE_CORE))
                ->formatStateUsing(fn (mixed $state): string => is_array($state)
                    ? (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : (string) $state)
                ->rules([fn (Get $get, ?IndustryContextProfile $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                    try {
                        $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
                    } catch (JsonException $exception) {
                        $fail(__('Invalid JSON: :message', ['message' => $exception->getMessage()]));

                        return;
                    }
                    $type = $record?->type ?? (string) ($get('type') ?: IndustryContextProfile::TYPE_CORE);
                    if ($type === IndustryContextProfile::TYPE_CORE) {
                        $errors = IndustryContextSchema::validate($decoded);
                        if ($errors !== []) {
                            $fail('JSON đúng cú pháp nhưng không đúng Industry Context Schema: '.implode(' ', $errors));
                        }

                        return;
                    }
                    try {
                        IndustryAuxiliarySchema::validatedOutput($type, $decoded);
                    } catch (Throwable $exception) {
                        $fail('JSON đúng cú pháp nhưng không đúng Industry Context Schema: '.$exception->getMessage());
                    }
                }])
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
            Tables\Actions\EditAction::make()->label('Sửa'),
            Tables\Actions\DeleteAction::make()->action(fn (IndustryContextProfile $record) => IndustryContextProfile::query()->where('key', $record->key)->delete()),
        ]);
    }

    /** @return array<string, string> */
    public static function languageOptions(): array
    {
        return ['vi' => 'Tiếng Việt', 'en' => 'English'];
    }

    public static function defaultLanguage(?string $suggested = null): string
    {
        return array_key_exists((string) $suggested, self::languageOptions()) ? (string) $suggested : 'vi';
    }

    public static function keyFromName(string $name): string
    {
        return Str::slug($name);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->logicalRepresentatives();
    }

    public static function resolveRecordRouteBinding(int|string $key): ?Model
    {
        return IndustryContextProfile::query()->find($key);
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
