<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SERVER-OWNED LEGACY / PENDING SERVER CUTOVER.
 *
 * Pricing plans belong to ops-server. Table kept as schema reference.
 * Do not drop until server cutover. Not required by client Service runtime.
 */
class ServicePlan extends Model
{
    protected $fillable = ['service_id', 'name', 'price', 'duration_days', 'limits'];

    protected $casts = ['limits' => 'array'];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }
}
