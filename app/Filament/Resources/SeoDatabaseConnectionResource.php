<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SeoDatabaseConnectionResource\Pages;
use App\Filament\Support\SeoDatabaseConnectionAccess;
use App\Filament\Support\SeoDatabaseConnectionBackupActions;
use App\Models\SeoDatabaseConnection;
use App\Models\User;
use App\Services\SiteServiceBindingService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SeoDatabaseConnectionResource extends Resource
{
    protected static ?string $model = SeoDatabaseConnection::class;

    protected static ?string $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'seo-database-connections';

    public static function getNavigationLabel(): string
    {
        return __('SEO Database Connections');
    }

    public static function getModelLabel(): string
    {
        return __('SEO Database Connection');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.system');
    }

    /**
     * Deprecated top-level product nav — use Admin → Dịch vụ → SEO Cấu hình.
     * Resource kept for legacy hash/runtime adapters; routes redirect.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        // Redirect-only shell — allow access so mount() can send users to ServiceConfigure.
        // Must not query retired credential tables.
        $user = auth()->user();

        return $user instanceof User
            && in_array((string) $user->role, [User::ROLE_OWNER, User::ROLE_ADMIN], true);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        // Table retired — never execute against seo_database_connections.
        return parent::getEloquentQuery()->whereRaw('0 = 1');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('site-service.seo_connection_info_section_title'))
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('site-service.seo_connection_name_label'))
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('hash_id')
                            ->label(__('site-service.seo_connection_hash_id_label'))
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (?SeoDatabaseConnection $record): bool => $record !== null)
                            ->helperText(fn (?SeoDatabaseConnection $record): ?string => $record
                                ? __('site-service.seo_connection_panel_url', ['url' => url($record->panelUrl())])
                                : null),

                        Forms\Components\Select::make('type')
                            ->label(__('site-service.seo_connection_type_label'))
                            ->options([
                                'auto' => __('site-service.seo_connection_type_auto'),
                                'manual' => __('site-service.seo_connection_type_manual'),
                            ])
                            ->default(fn (): string => (string) config('seo-content-ai.default_connection_type', 'manual'))
                            ->required()
                            ->live()
                            ->native(false),

                        Forms\Components\Toggle::make('is_active')
                            ->label(__('site-service.seo_connection_active_label'))
                            ->default(true),
                    ])
                    ->columns(2),

                Forms\Components\Section::make(__('site-service.seo_connection_database_section_title'))
                    ->description(__('site-service.seo_connection_database_section_description'))
                    ->schema([
                        Forms\Components\TextInput::make('database')
                            ->label(__('site-service.database_name_label'))
                            ->helperText(fn (Get $get): string => ($get('type') ?? 'auto') === 'auto'
                                ? __('site-service.seo_connection_database_auto_helper')
                                : __('site-service.seo_connection_database_manual_helper'))
                            ->required(fn (Get $get): bool => ($get('type') ?? '') === 'manual'),

                        Forms\Components\TextInput::make('host')
                            ->label(__('site-service.host_label'))
                            ->default('127.0.0.1')
                            ->visible(fn (Get $get): bool => ($get('type') ?? '') === 'manual')
                            ->required(fn (Get $get): bool => ($get('type') ?? '') === 'manual'),

                        Forms\Components\TextInput::make('port')
                            ->label(__('site-service.port_label'))
                            ->default('3306')
                            ->visible(fn (Get $get): bool => ($get('type') ?? '') === 'manual')
                            ->required(fn (Get $get): bool => ($get('type') ?? '') === 'manual'),

                        Forms\Components\TextInput::make('username')
                            ->label(__('site-service.username_label'))
                            ->visible(fn (Get $get): bool => ($get('type') ?? '') === 'manual')
                            ->required(fn (Get $get): bool => ($get('type') ?? '') === 'manual'),

                        Forms\Components\TextInput::make('password')
                            ->label(__('site-service.password_label'))
                            ->password()
                            ->revealable()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText(__('site-service.seo_connection_password_edit_helper'))
                            ->visible(fn (Get $get): bool => ($get('type') ?? '') === 'manual'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make(__('site-service.seo_connection_owner_section_title'))
                    ->description(__('site-service.seo_connection_owner_section_description'))
                    ->visible(false)
                    ->schema([
                        Forms\Components\Select::make('owner_id')
                            ->label(__('site-service.owner_label'))
                            ->options(fn (?SeoDatabaseConnection $record): array => app(SiteServiceBindingService::class)
                                ->eligibleOwnerSelectOptions(
                                    $record !== null
                                        ? [(int) ($record->users()->value('users.id') ?? 0)]
                                        : [],
                                ))
                            ->searchable()
                            ->required()
                            ->native(false),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('hash_id')
                    ->label(__('site-service.hash_label'))
                    ->copyable()
                    ->limit(16)
                    ->tooltip(fn (SeoDatabaseConnection $record): string => $record->hash_id),
                Tables\Columns\TextColumn::make('type')
                    ->badge(),
                Tables\Columns\TextColumn::make('database')
                    ->label(__('site-service.database_label')),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('owner_email')
                    ->label(__('site-service.owner_label'))
                    ->getStateUsing(fn (SeoDatabaseConnection $record): string => (string) ($record->users()->value('email') ?? '—')),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                SeoDatabaseConnectionBackupActions::exportTableAction()
                    ->visible(fn (SeoDatabaseConnection $record): bool => SeoDatabaseConnectionAccess::canEditConnection($record)),
                SeoDatabaseConnectionBackupActions::importTableAction()
                    ->visible(fn (SeoDatabaseConnection $record): bool => SeoDatabaseConnectionAccess::canEditConnection($record)),
                Tables\Actions\Action::make('open_panel')
                    ->label(__('site-service.seo_connection_open_panel_label'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (SeoDatabaseConnection $record): string => url($record->panelUrl()))
                    ->openUrlInNewTab()
                    ->visible(fn (SeoDatabaseConnection $record): bool => (bool) $record->is_active),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (SeoDatabaseConnection $record): bool => SeoDatabaseConnectionAccess::canDeleteConnection($record)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSeoDatabaseConnections::route('/'),
            'create' => Pages\CreateSeoDatabaseConnection::route('/create'),
            'edit' => Pages\EditSeoDatabaseConnection::route('/{record}/edit'),
        ];
    }
}
