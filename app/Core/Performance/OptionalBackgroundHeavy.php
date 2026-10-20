<?php

declare(strict_types=1);

namespace App\Core\Performance;

/**
 * Mark queue jobs that may be deferred when initiated from a hidden tab
 * under Mid/High adaptive background rules (or always deferred on Low).
 *
 * Essential work (autosave, session lease, recovery, single-article consistency)
 * must NOT implement this as true.
 */
interface OptionalBackgroundHeavy
{
    public function isOptionalBackgroundHeavy(): bool;
}
