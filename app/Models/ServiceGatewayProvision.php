<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ServiceGatewayProvisionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Service Gate provisioning row — readiness only, never secrets.
 */
class ServiceGatewayProvision extends Model
{
    protected $fillable = [
        'service_id',
        'public_slug',
        'status',
        'entitled',
        'execution_allowed',
        'requires_managed_credential',
        'managed_credential_id',
        'last_error',
        'last_provisioned_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => ServiceGatewayProvisionStatus::class,
            'entitled' => 'boolean',
            'execution_allowed' => 'boolean',
            'requires_managed_credential' => 'boolean',
            'last_provisioned_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function managedCredential(): BelongsTo
    {
        return $this->belongsTo(ServiceApiCredential::class, 'managed_credential_id');
    }
}
