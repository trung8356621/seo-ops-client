<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Local runtime Service catalog snapshot (ops-server provisioned).
 *
 * Addon installed ≠ Service active. Only ops-server may provision entitlements
 * via signed `services.apply`. Client Admin must not create/activate Services.
 */
class Service extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'addon_namespace',
        'db_connection',
        'is_active',
        'config',
        'service_key',
    ];

    protected $hidden = [
        'service_key',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_active' => 'boolean',
            'service_key' => 'encrypted',
        ];
    }

    public function databaseConnection(): HasOne
    {
        return $this->hasOne(ServiceDatabaseConnection::class);
    }

    public function hasServiceKey(): bool
    {
        return filled($this->service_key);
    }
}
