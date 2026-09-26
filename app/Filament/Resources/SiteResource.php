<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SiteResource\Pages;
use App\Models\Site;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    public static function canAccess(): bool
    {
        return auth()->user()?->role === \App\Models\User::ROLE_OWNER;
    }

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.management');
    }

    public static function getNavigationLabel(): string
    {
        return __('site-service.site_resource_navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('site-service.site_resource_model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('site-service.site_resource_plural_model_label');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([

                Forms\Components\TextInput::make('domain')
                    ->label(__('Domain'))
                    ->placeholder('example.com')
                    ->required()
                    ->unique(
                        table: Site::class,
                        column: 'domain',
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule->whereNull('deleted_at'),
                    )
                    ->maxLength(255)
                    // Tự động bóc tách domain nếu khách nhập full URL
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, callable $set) {
                        if (filter_var($state, FILTER_VALIDATE_URL) || str_contains($state, '://')) {
                            $domain = parse_url($state, PHP_URL_HOST);
                            if ($domain) {
                                $set('domain', $domain);
                            }
                        }
                    }),

                Forms\Components\Select::make('user_id')
                    ->label(__('Owner'))
                    ->relationship('user', 'name', fn (Builder $query) => $query->where('role', \App\Models\User::ROLE_OWNER))
                    ->searchable()
                    ->preload()
                    ->required(),

                // Bổ sung trường SSL
                Forms\Components\Toggle::make('ssl')
                    ->label(__('site-service.site_resource_ssl_label'))
                    ->default(true)
                    ->helperText(__('site-service.site_resource_ssl_helper'))
                    ->required(),

                Forms\Components\Select::make('status')
                    ->label(__('site-service.status_label'))
                    ->options([
                        'active' => __('site-service.status_active'),
                        'inactive' => __('site-service.status_inactive'),
                        'maintenance' => __('site-service.status_maintenance'),
                    ])
                    ->default('active')
                    ->required()
                    ->native(false),

                Forms\Components\Section::make(__('site-service.site_resource_wp_headless_section_title'))
                    ->description(__('site-service.site_resource_wp_headless_section_description'))
                    ->schema([
                        Forms\Components\TextInput::make('wp_headless.type')
                            ->label(__('site-service.type_label'))
                            ->placeholder(__('site-service.site_resource_wp_type_placeholder'))
                            ->maxLength(64),
                        Forms\Components\TextInput::make('wp_headless.public_url')
                            ->label(__('site-service.public_url_label'))
                            ->placeholder('https://example.com')
                            ->url()
                            ->maxLength(512),
                        Forms\Components\TextInput::make('wp_headless.headless_next_dev')
                            ->label(__('site-service.site_resource_headless_next_dev_label'))
                            ->placeholder('http://localhost:3000')
                            ->maxLength(255)
                            ->helperText(__('site-service.site_resource_headless_next_dev_helper')),
                        Forms\Components\Toggle::make('wp_headless.is_dev')
                            ->label(__('site-service.site_resource_wp_is_dev_label'))
                            ->default(false),
                    ])
                    ->columns(2)
                    ->visible(fn ($livewire) => method_exists($livewire, 'getRecord') && $livewire->getRecord()?->hasActiveWpHeadless()),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('domain')
                    ->label(__('Domain'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('Owner'))
                    ->searchable()
                    ->sortable(),
            ])
            ->defaultSort('domain')
            ->filters([
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['user', 'siteServices.service'])
            ->where('user_id', auth()->id());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSites::route('/'),
            'create' => Pages\CreateSite::route('/create'),
            'edit' => Pages\EditSite::route('/{record}/edit'),
        ];
    }
}
