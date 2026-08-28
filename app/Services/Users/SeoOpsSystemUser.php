<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Technical placeholder user for NOT NULL FK / writer columns.
 * Not a real assignee — Content Project gates treat this id as unassigned.
 */
final class SeoOpsSystemUser
{
    public const EMAIL = 'seo-ops-system@internal.omnichannel';

    public const NAME = 'SEO Ops System';

    private static ?int $cachedId = null;

    public static function ensure(): User
    {
        if (! Schema::hasColumn('users', 'is_system')) {
            throw new RuntimeException(
                'users.is_system column missing — run migrations before using SEO Ops System user.',
            );
        }

        $existing = User::query()
            ->withTrashed()
            ->where(function ($query): void {
                $query->where('email', self::EMAIL)
                    ->orWhere('is_system', true);
            })
            ->orderByRaw('CASE WHEN email = ? THEN 0 ELSE 1 END', [self::EMAIL])
            ->first();

        if ($existing instanceof User) {
            $dirty = false;
            if (! (bool) ($existing->is_system ?? false)) {
                $existing->is_system = true;
                $dirty = true;
            }
            if ($existing->trashed()) {
                $existing->restore();
                $dirty = true;
            }
            if ((string) ($existing->status ?? '') !== User::STATUS_BLOCK) {
                $existing->status = User::STATUS_BLOCK;
                $dirty = true;
            }
            if (trim((string) ($existing->name ?? '')) === ''
                || (string) ($existing->name ?? '') === self::EMAIL
            ) {
                $existing->name = self::NAME;
                $dirty = true;
            }
            if (trim((string) ($existing->seo_role ?? '')) === '') {
                $existing->seo_role = User::SEO_ROLE_MANAGER;
                $dirty = true;
            }
            if ($dirty) {
                $existing->save();
            }

            self::$cachedId = (int) $existing->getKey();

            return $existing;
        }

        $user = User::query()->create([
            'name' => self::NAME,
            'email' => self::EMAIL,
            'password' => Hash::make(Str::random(64)),
            'role' => User::ROLE_ADMIN,
            // NOT NULL column — not a real writer (role=admin excludes staff writer lists).
            'seo_role' => User::SEO_ROLE_MANAGER,
            'status' => User::STATUS_BLOCK,
            'is_system' => true,
            'parent_id' => null,
            'manager_id' => null,
        ]);

        self::$cachedId = (int) $user->getKey();

        return $user;
    }

    public static function id(): int
    {
        if (self::$cachedId !== null && self::$cachedId > 0) {
            return self::$cachedId;
        }

        $id = (int) User::query()
            ->where('is_system', true)
            ->orderBy('id')
            ->value('id');

        if ($id <= 0) {
            $id = (int) self::ensure()->getKey();
        }

        if ($id <= 0) {
            throw new RuntimeException('SEO Ops System user is unavailable.');
        }

        self::$cachedId = $id;

        return $id;
    }

    public static function isSystemUserId(?int $userId): bool
    {
        $userId = (int) ($userId ?? 0);
        if ($userId <= 0) {
            return false;
        }

        // After ensure()/id(), cache is the only system id for this process.
        if (self::$cachedId !== null) {
            return self::$cachedId === $userId;
        }

        return User::query()
            ->whereKey($userId)
            ->where('is_system', true)
            ->exists();
    }

    public static function isSystem(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if ((bool) ($user->is_system ?? false)) {
            return true;
        }

        return strcasecmp((string) ($user->email ?? ''), self::EMAIL) === 0;
    }

    /** @internal tests */
    public static function clearCache(): void
    {
        self::$cachedId = null;
    }

    /** @internal tests */
    public static function setCachedIdForTests(?int $id): void
    {
        self::$cachedId = $id !== null && $id > 0 ? $id : null;
    }
}
