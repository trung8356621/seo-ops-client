<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesCoreDatabaseConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class H1Installation extends Model
{
    use UsesCoreDatabaseConnection;

    protected $table = 'h1_installations';

    protected $fillable = [
        'installation_id',
        'product',
        'control_server_url',
        'status',
        'cutover_mode',
        'anchor_identity',
        'anchor_site_id',
        'client_version',
        'entitlement_revision',
        'credential_version',
        'last_command_id',
        'verified_snapshot',
        'validated_at',
        'pending_acks',
        'rotation_state',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_snapshot' => 'array',
            'pending_acks' => 'array',
            'rotation_state' => 'array',
            'validated_at' => 'datetime',
            'entitlement_revision' => 'integer',
            'credential_version' => 'integer',
            'anchor_site_id' => 'integer',
        ];
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(H1Credential::class, 'installation_id', 'installation_id');
    }
}
