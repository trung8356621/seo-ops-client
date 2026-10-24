<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Single authorization SSOT for Service Widget (header "Dịch vụ" + session API).
 * Installation Owner only — Staff / Manager / other roles denied.
 *
 * Call {@see self::allows()} from both the Filament header hook and
 * ServiceWidgetSessionController — do not duplicate role checks.
 */
final class ServiceWidgetPolicy
{
    public function access(User $user): bool
    {
        return $user->isOwner();
    }

    public static function allows(?object $user): bool
    {
        return $user instanceof User && (new self)->access($user);
    }
}
