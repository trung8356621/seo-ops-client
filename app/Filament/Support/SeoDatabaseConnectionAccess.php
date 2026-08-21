<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\SeoDatabaseConnection;
use App\Models\User;
use App\Services\SiteServiceBindingService;
use Illuminate\Database\Eloquent\Builder;

final class SeoDatabaseConnectionAccess
{
    public static function isOwner(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user?->role === User::ROLE_OWNER;
    }

    public static function isEligibleOwner(?User $user = null): bool
    {
        $user ??= auth()->user();
        if ($user?->role !== User::ROLE_OWNER) {
            return false;
        }

        return app(SiteServiceBindingService::class)->ownerHasActiveSeoService((int) $user->id);
    }

    public static function canAccessResource(): bool
    {
        return self::isEligibleOwner();
    }

    public static function canCreateConnection(): bool
    {
        return self::isEligibleOwner() && ! self::ownerHasConnection();
    }

    public static function canEditConnection(SeoDatabaseConnection $record): bool
    {
        $user = auth()->user();
        if ($user?->role !== User::ROLE_OWNER) {
            return false;
        }

        return $record->users()->whereKey($user->id)->exists();
    }

    public static function canDeleteConnection(SeoDatabaseConnection $record): bool
    {
        return self::canEditConnection($record);
    }

    public static function ownerHasConnection(?User $user = null): bool
    {
        $user ??= auth()->user();
        if ($user?->role !== User::ROLE_OWNER) {
            return false;
        }

        return SeoDatabaseConnection::query()
            ->whereHas('users', fn (Builder $query): Builder => $query->whereKey($user->id))
            ->exists();
    }

    /**
     * @return list<int>
     */
    public static function resolveOwnerUserIdsForSave(): array
    {
        $user = auth()->user();
        if ($user?->role !== User::ROLE_OWNER) {
            return [];
        }

        return [(int) $user->id];
    }
}
