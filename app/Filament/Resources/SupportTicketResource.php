<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SupportTicketResource\Pages;
use App\Models\SupportTicket;
use App\Models\User;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class SupportTicketResource extends Resource
{
    protected static ?string $model = SupportTicket::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationGroup = 'Hệ thống';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'support-tickets';

    public static function getNavigationLabel(): string
    {
        return (string) __('support_ticket.admin.nav');
    }

    public static function getModelLabel(): string
    {
        return (string) __('support_ticket.admin.model');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('support_ticket.admin.plural');
    }

    public static function canAccess(): bool
    {
        $role = (string) (auth()->user()?->role ?? '');

        return in_array($role, [User::ROLE_OWNER, User::ROLE_ADMIN], true);
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

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label(__('support_ticket.admin.columns.id'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('support_ticket.admin.columns.created_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('support_ticket.admin.columns.user'))
                    ->description(fn (SupportTicket $record): ?string => $record->user?->email)
                    ->searchable(),
                Tables\Columns\TextColumn::make('title')
                    ->label(__('support_ticket.admin.columns.title'))
                    ->limit(48)
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('support_ticket.admin.columns.status'))
                    ->badge(),
                Tables\Columns\TextColumn::make('metadata.service')
                    ->label(__('support_ticket.admin.columns.service'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('attachment_count')
                    ->label(__('support_ticket.admin.columns.attachments'))
                    ->state(function (SupportTicket $record): int {
                        $attachments = $record->metadata['attachments'] ?? [];

                        return is_array($attachments) ? count($attachments) : 0;
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label(__('support_ticket.admin.view')),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make()
                    ->schema([
                        Infolists\Components\TextEntry::make('id')
                            ->label(__('support_ticket.admin.columns.id')),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label(__('support_ticket.admin.columns.created_at'))
                            ->dateTime('Y-m-d H:i:s'),
                        Infolists\Components\TextEntry::make('user.name')
                            ->label(__('support_ticket.admin.columns.user'))
                            ->formatStateUsing(function (?string $state, SupportTicket $record): string {
                                $email = (string) ($record->user?->email ?? '');

                                return trim($state.' '.($email !== '' ? "({$email})" : ''));
                            }),
                        Infolists\Components\TextEntry::make('status')
                            ->label(__('support_ticket.admin.columns.status'))
                            ->badge(),
                        Infolists\Components\TextEntry::make('metadata.service')
                            ->label(__('support_ticket.admin.columns.service'))
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('metadata.page_url')
                            ->label(__('support_ticket.admin.columns.page_url'))
                            ->placeholder('—')
                            ->url(fn (?string $state): ?string => filled($state) ? $state : null, true),
                        Infolists\Components\TextEntry::make('connection_hash')
                            ->label(__('support_ticket.admin.columns.connection_hash'))
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('title')
                            ->label(__('support_ticket.admin.columns.title'))
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('body')
                            ->label(__('support_ticket.admin.columns.body'))
                            ->markdown()
                            ->prose()
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('attachments_list')
                            ->label(__('support_ticket.admin.columns.attachments'))
                            ->state(function (SupportTicket $record): string {
                                $attachments = $record->metadata['attachments'] ?? [];
                                if (! is_array($attachments) || $attachments === []) {
                                    return (string) __('support_ticket.admin.empty_attachments');
                                }

                                $lines = [];
                                foreach ($attachments as $row) {
                                    if (! is_array($row)) {
                                        continue;
                                    }
                                    $name = (string) ($row['name'] ?? 'attachment');
                                    $url = (string) ($row['url'] ?? '');
                                    $lines[] = $url !== '' ? $name.' — '.$url : $name;
                                }

                                return implode("\n", $lines);
                            })
                            ->markdown()
                            ->columnSpanFull(),
                        Infolists\Components\ViewEntry::make('attachment_previews')
                            ->label('')
                            ->view('filament.resources.support-ticket-resource.attachment-previews')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupportTickets::route('/'),
            'view' => Pages\ViewSupportTicket::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with('user');
    }
}
