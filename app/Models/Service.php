<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Local runtime service catalog / activation snapshot.
 *
 * Future ops-server control command `services.apply` stores slug + is_active + config.
 * Runtime boots only active installed addons (see AppServiceProvider).
 * Pricing/plans belong to ops-server, not this client.
 */
class Service extends Model
{
    protected $fillable = ['name', 'slug', 'addon_namespace', 'db_connection', 'is_active', 'config'];

    protected $casts = ['config' => 'array'];
}
