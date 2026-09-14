<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesCoreDatabaseConnection;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Spatie Permission on core DB — addon permissions only.
 */
class Permission extends SpatiePermission
{
    use UsesCoreDatabaseConnection;
}
