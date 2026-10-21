<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton-ish safe license presentation for Control Gate UI.
 * No installation secrets / machine credentials.
 */
class LocalEntitlementPresentation extends Model
{
    protected $fillable = [
        'connected',
        'license_status',
        'product_name',
        'plan_name',
        'starts_at',
        'ends_at',
        'entitled_services',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'connected' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'entitled_services' => 'array',
            'applied_at' => 'datetime',
        ];
    }
}
