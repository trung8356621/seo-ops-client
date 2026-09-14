<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesCoreDatabaseConnection;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Spatie Role on core DB — addon roles only (seo.*, seeding.*, …).
 * Core account type remains users.role (owner|staff).
 */
class Role extends SpatieRole
{
    use UsesCoreDatabaseConnection;
}
