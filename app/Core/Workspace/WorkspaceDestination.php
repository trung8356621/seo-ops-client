<?php

declare(strict_types=1);

namespace App\Core\Workspace;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Filament\Panel;
use Throwable;

/**
 * One launcher destination on the Access Hub (/workspace).
 * Authorization must reuse existing panel/access SSOT — not duplicate role matrices.
 */
final class WorkspaceDestination
{
    /**
     * @param  Closure(User): bool|null  $canAccess  Custom gate; preferred when panelId is insufficient
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $url,
        public readonly int $sort = 100,
        public readonly ?string $description = null,
        public readonly ?string $icon = null,
        public readonly ?string $panelId = null,
        public readonly ?Closure $canAccess = null,
    ) {}

    public function allows(User $user): bool
    {
        if ((string) ($user->status ?? '') === User::STATUS_BLOCK) {
            return false;
        }

        if ($this->canAccess instanceof Closure) {
            try {
                return (bool) ($this->canAccess)($user);
            } catch (Throwable) {
                return false;
            }
        }

        if ($this->panelId === null || $this->panelId === '') {
            return false;
        }

        try {
            $panel = Filament::getPanel($this->panelId);
        } catch (Throwable) {
            return false;
        }

        if (! $panel instanceof Panel) {
            return false;
        }

        return $user->canAccessPanel($panel);
    }
}
