<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Authorization for OPS Support Widget header + session API.
 * Any authenticated panel user may open Ticket (not Owner-only).
 */
final class SupportWidgetPolicy
{
    public function access(User $user): bool
    {
        return $user->exists;
    }

    public static function allows(?object $user): bool
    {
        return $user instanceof User && (new self)->access($user);
    }
}
