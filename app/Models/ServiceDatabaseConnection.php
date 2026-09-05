<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Infrastructure credentials for one Service (1:1).
 * Not a product identity — subordinate to {@see Service}.
 */
class ServiceDatabaseConnection extends Model
{
    protected $fillable = [
        'service_id',
        'type',
        'driver',
        'host',
        'port',
        'database',
        'username',
        'password',
        'is_active',
        'last_tested_at',
        'last_test_ok',
        'last_error',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'is_active' => 'boolean',
            'last_test_ok' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function isManual(): bool
    {
        return ($this->type ?? 'manual') === 'manual';
    }
}
