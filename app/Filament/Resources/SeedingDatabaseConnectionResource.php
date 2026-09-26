<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SeedingDatabaseConnectionResource\Pages;
use App\Models\SeedingDatabaseConnection;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

final class SeedingDatabaseConnectionResource extends Resource
{
    protected static ?string $model = SeedingDatabaseConnection::class;

    protected static ?string $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?string $navigationLabel = null;

    protected static ?string $modelLabel = null;

    protected static ?string $pluralModelLabel = null;

    protected static ?string $slug = 'seeding-database-connections';

    protected static ?int $navigationSort = 12;

    public static function getNavigationGroup(): ?string
    {
        return __('site-service.system_nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('site-service.seeding_connection_navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('site-service.seeding_connection_model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('site-service.seeding_connection_plural_model_label');
    }

    /** Deprecated top-level nav — use Admin → Dịch vụ → Seeding Cấu hình. */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && in_array((string) $user->role, [User::ROLE_OWNER, User::ROLE_ADMIN], true);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        // Table retired — never execute against seeding_database_connections.
        return parent::getEloquentQuery()->whereRaw('0 = 1');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('site-service.seeding_connection_section_title'))
                    ->description(__('site-service.seeding_connection_section_description'))
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('site-service.seeding_connection_name_label'))
                            ->required()
                            ->maxLength(255)
                            ->default(__('site-service.seeding_connection_name_default')),

                        Forms\Components\Select::make('type')
                            ->label(__('site-service.seeding_connection_type_label'))
                            ->options([
                                'manual' => __('site-service.seeding_connection_type_manual'),
                            ])
                            ->default('manual')
                            ->required()
                            ->native(false),

                        Forms\Components\Toggle::make('is_active')
                            ->label(__('site-service.seeding_connection_active_label'))
                            ->default(true),
                    ])
                    ->columns(2),

                Forms\Components\Section::make(__('site-service.seeding_connection_database_section_title'))
                    ->schema([
                        Forms\Components\TextInput::make('database')
                            ->label(__('site-service.database_name_label'))
                            ->default('omi_seeding')
                            ->required()
                            ->helperText(__('site-service.seeding_connection_database_helper')),

                        Forms\Components\TextInput::make('host')
                            ->label(__('site-service.host_label'))
                            ->default('127.0.0.1')
                            ->required(),

                        Forms\Components\TextInput::make('port')
                            ->label(__('site-service.port_label'))
                            ->default('3306')
                            ->required(),

                        Forms\Components\TextInput::make('username')
                            ->label(__('site-service.username_label'))
                            ->required(),

                        Forms\Components\TextInput::make('password')
                            ->label(__('site-service.password_label'))
                            ->password()
                            ->revealable()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText(fn (?SeedingDatabaseConnection $record): string => $record
                                ? __('site-service.seeding_connection_password_edit_helper')
                                : __('site-service.seeding_connection_password_create_helper')),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('database')->label(__('site-service.database_label')),
                Tables\Columns\TextColumn::make('host'),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSeedingDatabaseConnections::route('/'),
            'create' => Pages\CreateSeedingDatabaseConnection::route('/create'),
            'edit' => Pages\EditSeedingDatabaseConnection::route('/{record}/edit'),
        ];
    }
}
