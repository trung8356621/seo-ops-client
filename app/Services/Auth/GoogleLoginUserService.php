<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Google OAuth user provisioning.
 *
 * `users.name` is initialized from Google only when creating a new account.
 * Later logins must not overwrite a manager-edited display name.
 */
final class GoogleLoginUserService
{
    public function provisionFromGoogleUser(object $googleUser): User
    {
        return $this->provision(
            email: (string) ($googleUser->email ?? ''),
            googleName: (string) ($googleUser->name ?? ''),
            googleId: $googleUser->id !== null ? (string) $googleUser->id : null,
            avatar: isset($googleUser->avatar) && $googleUser->avatar !== null
                ? (string) $googleUser->avatar
                : null,
        );
    }

    public function provision(
        string $email,
        string $googleName,
        ?string $googleId = null,
        ?string $avatar = null,
    ): User {
        $email = strtolower(trim($email));
        if ($email === '') {
            throw new InvalidArgumentException('Google login requires an email.');
        }

        $googleName = trim($googleName);
        $googleId = $googleId !== null ? trim($googleId) : null;
        $avatar = $avatar !== null ? trim($avatar) : null;

        $existing = User::query()->where('email', $email)->first();
        if ($existing instanceof User) {
            $this->syncProviderFields($existing, $googleId, $avatar);

            return $existing;
        }

        $user = new User;
        $user->email = $email;
        $user->name = $googleName !== '' ? $googleName : $email;
        $user->password = Hash::make(Str::random(16));
        $this->syncProviderFields($user, $googleId, $avatar);
        $user->save();

        return $user;
    }

    private function syncProviderFields(User $user, ?string $googleId, ?string $avatar): void
    {
        if ($googleId !== null && $googleId !== '') {
            $user->google_id = $googleId;
        }

        if ($avatar !== null && $avatar !== '') {
            $user->avatar = $avatar;
        }

        if ($user->exists && $user->isDirty(['google_id', 'avatar'])) {
            $user->save();
        }
    }
}
