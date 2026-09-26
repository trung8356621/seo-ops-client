<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use Omnichannel\Addons\SearchFoundation\Support\SeoSiteServiceDatabaseConfigurator;
use App\Filament\Resources\SiteServiceResource\Pages;
use App\Models\Service;
use App\Models\Site;
use App\Models\SiteService;
use App\Models\User;
use App\Services\SiteServiceBindingService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SiteServiceResource extends Resource
{
    protected static ?string $model = SiteService::class;

    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?int $navigationSort = 2;

    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationLabel(): string
    {
        return __('site-service.site_service_navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.management');
    }

    public static function getModelLabel(): string
    {
        return __('site-service.site_service_model_label');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function canView(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('site-service.site_service_activation_section_title'))
                    ->description(__('site-service.site_service_activation_section_description'))
                    ->schema([
                        Forms\Components\Select::make('bound_type')
                            ->label(__('site-service.site_service_bound_to_label'))
                            ->options([
                                SiteServiceBindingService::BOUND_SITE => __('site-service.site_service_bound_site_option'),
                                SiteServiceBindingService::BOUND_USER => __('site-service.site_service_bound_user_option'),
                            ])
                            ->default(SiteServiceBindingService::BOUND_SITE)
                            ->required()
                            ->live()
                            ->native(false)
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                if ($state === SiteServiceBindingService::BOUND_USER) {
                                    $set('site_id', null);

                                    return;
                                }

                                $set('user_id', null);
                            }),

                        Forms\Components\Select::make('site_id')
                            ->label(__('site-service.site_service_select_site_label'))
                            ->options(fn (): array => static::siteSelectOptions())
                            ->required(fn (Get $get): bool => ($get('bound_type') ?? SiteServiceBindingService::BOUND_SITE) === SiteServiceBindingService::BOUND_SITE)
                            ->visible(fn (Get $get): bool => ($get('bound_type') ?? SiteServiceBindingService::BOUND_SITE) === SiteServiceBindingService::BOUND_SITE)
                            ->searchable()
                            ->preload(),

                        Forms\Components\Select::make('user_id')
                            ->label(__('site-service.site_service_select_owner_label'))
                            ->options(fn (): array => static::ownerSelectOptions())
                            ->required(fn (Get $get): bool => ($get('bound_type') ?? '') === SiteServiceBindingService::BOUND_USER)
                            ->visible(fn (Get $get): bool => ($get('bound_type') ?? '') === SiteServiceBindingService::BOUND_USER)
                            ->searchable()
                            ->preload(),

                        Forms\Components\Select::make('service_id')
                            ->label(__('site-service.site_service_select_service_label'))
                            ->options(fn () => Service::where('is_active', true)->pluck('name', 'id'))
                            ->required()
                            ->live() // Kích hoạt tương tác thời gian thực
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                if (! $state) {
                                    $set('settings', []);

                                    return;
                                }

                                // 1. Tìm thông tin Service được chọn
                                $service = Service::find($state);
                                if (! $service) {
                                    return;
                                }

                                // 2. Xác định Class Settings của Addon dựa trên namespace
                                // Ví dụ: App\Addons\SeoContentAi\Settings
                                $providerNamespace = $service->addon_namespace;
                                $settingsClass = str_replace(
                                    class_basename($providerNamespace),
                                    'Settings',
                                    $providerNamespace
                                );

                                // 3. Nếu class tồn tại, gọi getDefaults() và đổ vào trường settings
                                if (class_exists($settingsClass) && method_exists($settingsClass, 'getDefaults')) {
                                    $defaults = (new $settingsClass)->getDefaults();
                                    $set('settings', $defaults);
                                    $set('seo_db_config_type', (string) ($defaults['db_config_type'] ?? 'auto'));
                                } else {
                                    $set('settings', []);
                                    $set('seo_db_config_type', 'auto');
                                }
                            }),

                        Forms\Components\Select::make('status')
                            ->label(__('site-service.status_label'))
                            ->options([
                                'active' => __('site-service.status_active'),
                                'inactive' => __('site-service.status_inactive'),
                                'maintenance' => __('site-service.status_maintenance'),
                            ])
                            ->default('active')
                            ->required(),
                    ])->columns(2),

                ...SeoSiteServiceDatabaseConfigurator::formSchema(),

                Forms\Components\Section::make(__('site-service.site_service_settings_section_title'))
                    ->description(__('site-service.site_service_settings_section_description'))
                    ->schema([
                        Forms\Components\KeyValue::make('settings')
                            ->label(__('site-service.site_service_custom_configuration_label'))
                            ->keyLabel(__('site-service.site_service_parameter_name_label'))
                            ->valueLabel(__('site-service.site_service_parameter_value_label'))
                            ->addActionLabel(__('site-service.site_service_add_parameter_label'))
                            ->helperText(__('site-service.site_service_settings_helper'))
                            ->formatStateUsing(function (?array $state): array {
                                if (! is_array($state)) {
                                    return [];
                                }

                                $filtered = $state;
                                unset($filtered['db_config_type']);

                                return $filtered;
                            }),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        $isOwner = auth()->user()?->role === User::ROLE_OWNER;

        $actions = $isOwner
            ? [
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]
            : [
                Tables\Actions\ViewAction::make(),
            ];

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('service'))
            ->columns([
                Tables\Columns\TextColumn::make('bound_type')
                    ->label(__('site-service.site_service_bound_label'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === SiteServiceBindingService::BOUND_USER
                        ? __('site-service.site_service_bound_user_short')
                        : __('site-service.site_service_bound_site_short')),

                Tables\Columns\TextColumn::make('bound_target')
                    ->label(__('site-service.site_service_bound_to_label'))
                    ->state(fn (?SiteService $record): string => $record instanceof SiteService ? $record->boundLabel() : '')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $inner) use ($search): void {
                            $inner->whereHas('site', fn (Builder $site): Builder => $site->where('domain', 'like', "%{$search}%"))
                                ->orWhereHas('user', fn (Builder $user): Builder => $user->where('email', 'like', "%{$search}%"));
                        });
                    }),

                Tables\Columns\TextColumn::make('site.domain')
                    ->label(__('site-service.website_label'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('service.name')
                    ->label(__('site-service.site_service_name_label'))
                    ->badge()
                    ->color('info')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('site-service.status_label'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'danger',
                        'maintenance' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => __($state)),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('site-service.site_service_last_updated_label'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('site_id')
                    ->label(__('site-service.site_service_filter_by_site_label'))
                    ->options(fn (): array => static::siteSelectOptions()),

                Tables\Filters\SelectFilter::make('service_id')
                    ->label(__('site-service.site_service_filter_by_service_label'))
                    ->relationship('service', 'name'),
            ])
            ->actions($actions)
            ->bulkActions(
                $isOwner
                    ? [
                        Tables\Actions\BulkActionGroup::make([
                            Tables\Actions\DeleteBulkAction::make(),
                        ]),
                    ]
                    : [],
            );
    }

    /**
     * @return array<int|string, string>
     */
    public static function siteSelectOptions(): array
    {
        $query = Site::query()->orderBy('domain');

        if (auth()->user()?->role === User::ROLE_OWNER) {
            $query->where('user_id', auth()->id());
        } else {
            $query->whereRaw('1 = 0');
        }

        return $query->pluck('domain', 'id')->all();
    }

    /**
     * @return array<int|string, string>
     */
    public static function ownerSelectOptions(): array
    {
        return User::query()
            ->where('role', User::ROLE_OWNER)
            ->orderBy('email')
            ->pluck('email', 'id')
            ->all();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Owner chỉ thấy dịch vụ gắn với account của mình
        if (auth()->check() && auth()->user()->role === User::ROLE_OWNER) {
            $ownerId = (int) auth()->id();

            return $query->where(function (Builder $inner) use ($ownerId): void {
                $inner->where(function (Builder $userBound) use ($ownerId): void {
                    $userBound->where('bound_type', SiteServiceBindingService::BOUND_USER)
                        ->where('user_id', $ownerId);
                })->orWhereHas('site', function (Builder $siteQuery) use ($ownerId): void {
                    $siteQuery->where('user_id', $ownerId);
                });
            });
        }

        return $query->whereRaw('1 = 0');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSiteServices::route('/'),
            'create' => Pages\CreateSiteService::route('/create'),
            'view' => Pages\ViewSiteService::route('/{record}'),
            'edit' => Pages\EditSiteService::route('/{record}/edit'),
        ];
    }
}
