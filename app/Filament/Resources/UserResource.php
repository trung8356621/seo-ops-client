<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Services\Users\UserHierarchyService;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormInputAction;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Thành viên';

    protected static ?string $modelLabel = 'Thành viên';

    protected static ?string $pluralModelLabel = 'Thành viên';

    public static function canAccess(): bool
    {
        return (string) (auth()->user()?->role ?? '') === User::ROLE_OWNER;
    }

    public static function form(Form $form): Form
    {
        $hierarchy = app(UserHierarchyService::class);
        $addonTabs = app(\App\Core\Members\MembersSectionRegistry::class)->formTabs();

        $coreTab = Forms\Components\Tabs\Tab::make('Tài khoản')
            ->icon('heroicon-o-user-circle')
            ->schema([
                Forms\Components\Section::make(__('Account'))
                    ->extraAttributes(['class' => 'max-w-4xl'])
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('Display name'))
                            ->helperText('Biệt danh / tên hiển thị (users.name).')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Forms\Components\TextInput::make('password')
                            ->label(__('Password'))
                            ->required(fn ($livewire): bool => $livewire instanceof Pages\CreateUser)
                            ->maxLength(255)
                            ->default(fn ($livewire): string => $livewire instanceof Pages\CreateUser
                                ? self::generateRandomPassword()
                                : '')
                            ->helperText(fn ($livewire): ?string => $livewire instanceof Pages\EditUser
                                ? __('Leave blank to keep the current password.')
                                : __('Random password by default. Use reload to generate a new one.'))
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->suffixAction(
                                FormInputAction::make('regenerate_password')
                                    ->label(__('Reload'))
                                    ->icon('heroicon-o-arrow-path')
                                    ->action(fn (Set $set): mixed => $set('password', self::generateRandomPassword()))
                            ),
                        Forms\Components\Select::make('role')
                            ->label(__('Rule'))
                            ->options(self::roleOptionsForActor())
                            ->required()
                            ->live()
                            ->native(false)
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                if ($state === User::ROLE_OWNER) {
                                    $set('parent_id', null);
                                    $set('manager_id', null);
                                }
                            }),
                        Forms\Components\Select::make('status')
                            ->label(__('Status'))
                            ->options([
                                'normal' => 'Hoạt động',
                                'block' => 'Đã khóa',
                                'pending' => 'Chờ duyệt',
                            ])
                            ->required()
                            ->native(false),
                    ])
                    ->columns(2),

                Forms\Components\Section::make(__('Organization'))
                    ->extraAttributes(['class' => 'max-w-4xl'])
                    ->schema([
                        Forms\Components\Select::make('parent_id')
                            ->label('Chủ tài khoản (Owner)')
                            ->options(fn (): array => $hierarchy->ownersForSelect()
                                ->mapWithKeys(fn (User $u): array => [$u->id => $u->display_name.' ('.$u->email.')'])
                                ->all())
                            ->default(fn (): ?int => (string) (auth()->user()?->role ?? '') === User::ROLE_OWNER
                                ? (int) auth()->id()
                                : null)
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->live()
                            ->visible(fn (Get $get): bool => (string) $get('role') === User::ROLE_STAFF)
                            ->required(fn (Get $get): bool => (string) $get('role') === User::ROLE_STAFF
                                && (string) (auth()->user()?->role ?? '') === User::ROLE_OWNER)
                            ->disabled(fn (): bool => (string) (auth()->user()?->role ?? '') === User::ROLE_OWNER)
                            ->dehydrated()
                            ->helperText('Staff thuộc Owner này (parent_id). Không còn cấp Manager trung gian.'),
                    ])
                    ->columns(2)
                    ->visible(fn (Get $get): bool => (string) $get('role') === User::ROLE_STAFF),
            ]);

        return $form
            ->schema([
                Forms\Components\Tabs::make('member_tabs')
                    ->tabs([
                        $coreTab,
                        ...$addonTabs,
                    ])
                    ->persistTabInQueryString('tab')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                Tables\Columns\ImageColumn::make('avatar')
                    ->label(label: __('Avatar'))
                    ->circular()
                    ->defaultImageUrl(fn ($record) => 'https://ui-avatars.com/api/?name='.urlencode($record->name)),

                Tables\Columns\TextColumn::make('name')
                    ->label(__('Display name'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage(__('Đã copy email'))
                    ->copyMessageDuration(2000),

                Tables\Columns\TextColumn::make('role')
                    ->label(__('Rule'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin' => 'danger',
                        'owner' => 'success',
                        'staff' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'owner' => 'Owner',
                        'staff' => 'Staff',
                        'admin' => 'Admin',
                        'manager' => 'Staff (legacy manager)',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('owner.name')
                    ->label('Chủ tài khoản')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'normal' => 'success',
                        'block' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('created_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultGroup('owner.name')
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->label(__('Filter by rule'))
                    ->options([
                        'owner' => 'Owner',
                        'staff' => 'Staff',
                    ]),
                Tables\Filters\SelectFilter::make('parent_id')
                    ->label('Chủ tài khoản')
                    ->relationship('owner', 'name', fn (Builder $query) => $query->where('role', User::ROLE_OWNER))
                    ->searchable()
                    ->preload(),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('customizeMember')
                    ->label('Tùy chỉnh')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->modalHeading('Tùy chỉnh thành viên')
                    ->modalSubmitActionLabel(__('Lưu'))
                    ->modalCancelActionLabel(__('Huỷ'))
                    ->modalWidth('md')
                    ->fillForm(function (User $record): array {
                        $addon = app(\App\Core\Members\MembersSectionRegistry::class)
                            ->fillCustomizeModal($record);

                        return array_merge([
                            'name' => (string) ($record->name ?? ''),
                        ], $addon);
                    })
                    ->form(function (): array {
                        $addonFields = app(\App\Core\Members\MembersSectionRegistry::class)
                            ->customizeModalSchema();

                        return [
                            Forms\Components\Section::make('Tài khoản')
                                ->schema([
                                    Forms\Components\TextInput::make('name')
                                        ->label(__('Display name'))
                                        ->helperText('Biệt danh / tên hiển thị — lưu vào users.name')
                                        ->required()
                                        ->maxLength(255),
                                ]),
                            ...$addonFields,
                        ];
                    })
                    ->action(function (User $record, array $data): void {
                        $record->update([
                            'name' => trim((string) ($data['name'] ?? '')),
                        ]);

                        app(\App\Core\Members\MembersSectionRegistry::class)
                            ->afterUserSaved($record->fresh() ?? $record, $data);

                        \Filament\Notifications\Notification::make()
                            ->title('Đã lưu tùy chỉnh thành viên')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (User $record): bool => ! $record->isSystemUser())
                    ->before(function (User $record): void {
                        app(UserHierarchyService::class)->assertCanDelete($record);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['owner', 'roles'])
            ->where(function (Builder $builder): void {
                $builder->where('is_system', false)->orWhereNull('is_system');
            });
        $actor = auth()->user();

        if ($actor instanceof User && (string) $actor->role === User::ROLE_OWNER) {
            return $query->where(function (Builder $builder) use ($actor): void {
                $builder->whereKey($actor->id)
                    ->orWhere('parent_id', $actor->id);
            });
        }

        return $query->whereRaw('1 = 0');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function generateRandomPassword(int $length = 16): string
    {
        return Str::password($length, letters: true, numbers: true, symbols: false);
    }

    /**
     * @return array<string, string>
     */
    private static function roleOptionsForActor(): array
    {
        if ((string) (auth()->user()?->role ?? '') === User::ROLE_OWNER) {
            return [
                User::ROLE_STAFF => 'Nhân viên (Staff)',
            ];
        }

        return [
            User::ROLE_OWNER => 'Chủ tài khoản (Owner)',
            User::ROLE_STAFF => 'Nhân viên (Staff)',
        ];
    }
}
