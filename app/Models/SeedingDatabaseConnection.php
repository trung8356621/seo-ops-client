<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Infrastructure credentials for the Seeding DB plane (`omi_seeding`).
 * Not a Seeding business model — passwords encrypted; never store in services.config.
 */
class SeedingDatabaseConnection extends Model
{
    protected $fillable = [
        'name',
        'type',
        'host',
        'port',
        'database',
        'username',
        'password',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    public function isManual(): bool
    {
        return ($this->type ?? 'manual') === 'manual';
    }
}
