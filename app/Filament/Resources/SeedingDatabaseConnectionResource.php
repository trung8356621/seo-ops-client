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

    protected static ?string $navigationLabel = 'Seeding Database';

    protected static ?string $modelLabel = 'Seeding DB connection';

    protected static ?string $pluralModelLabel = 'Seeding DB connections';

    protected static ?string $slug = 'seeding-database-connections';

    protected static ?int $navigationSort = 12;

    public static function getNavigationGroup(): ?string
    {
        return 'Hệ thống';
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
        if (! static::canAccess()) {
            return false;
        }

        return SeedingDatabaseConnection::query()->count() === 0;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Kết nối Seeding')
                    ->description('Infrastructure only — không tạo business schema. Database mặc định: omi_seeding.')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Tên gợi nhớ')
                            ->required()
                            ->maxLength(255)
                            ->default('Seeding local'),

                        Forms\Components\Select::make('type')
                            ->label('Loại cấu hình')
                            ->options([
                                'manual' => 'Thủ công',
                            ])
                            ->default('manual')
                            ->required()
                            ->native(false),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Kích hoạt')
                            ->default(true),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Cấu hình Database')
                    ->schema([
                        Forms\Components\TextInput::make('database')
                            ->label('Database name')
                            ->default('omi_seeding')
                            ->required()
                            ->helperText('Không dùng omi_seo_ai.'),

                        Forms\Components\TextInput::make('host')
                            ->label('Host')
                            ->default('127.0.0.1')
                            ->required(),

                        Forms\Components\TextInput::make('port')
                            ->label('Port')
                            ->default('3306')
                            ->required(),

                        Forms\Components\TextInput::make('username')
                            ->label('Username')
                            ->required(),

                        Forms\Components\TextInput::make('password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText(fn (?SeedingDatabaseConnection $record): string => $record
                                ? 'Để trống nếu không đổi mật khẩu.'
                                : 'Mật khẩu MySQL (không lưu vào services.config).'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('database')->label('Database'),
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
