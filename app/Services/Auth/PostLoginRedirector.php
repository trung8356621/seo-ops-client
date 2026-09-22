<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;

/**
 * Canonical post-login destination — no intended URL / hash preservation.
 */
final class PostLoginRedirector
{
    public function urlFor(?User $user): string
    {
        if (! $user instanceof User) {
            return route('login');
        }

        if ($user->isStaff() || $user->isManager()) {
            return url('/workspace');
        }

        // Owner / admin: Admin panel (existing Google / control-plane behavior).
        if (in_array((string) $user->role, [User::ROLE_OWNER, User::ROLE_ADMIN], true)) {
            return url('/admin');
        }

        return url('/workspace');
    }
}
