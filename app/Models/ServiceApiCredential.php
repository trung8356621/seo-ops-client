<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * External/service API credential (many per Service).
 * Independent of {@see Service::$service_key} provisioning secret.
 */
class ServiceApiCredential extends Model
{
    protected $fillable = [
        'service_id',
        'name',
        'key_prefix',
        'key_hash',
        'scopes',
        'expires_at',
        'revoked_at',
        'last_used_at',
        'created_by',
    ];

    protected $hidden = [
        'key_hash',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(?Carbon $now = null): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->lessThanOrEqualTo($now ?? now());
    }

    public function isUsable(?Carbon $now = null): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired($now);
    }

    public function hasScope(string $scope): bool
    {
        $scope = trim($scope);
        if ($scope === '') {
            return false;
        }

        $scopes = $this->scopes;
        if (! is_array($scopes)) {
            return false;
        }

        foreach ($scopes as $item) {
            if (! is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item === '*' || $item === $scope) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function scopeList(): array
    {
        $scopes = $this->scopes;
        if (! is_array($scopes)) {
            return [];
        }

        $out = [];
        foreach ($scopes as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return $out;
    }

    public function statusLabel(?Carbon $now = null): string
    {
        if ($this->isRevoked()) {
            return 'revoked';
        }
        if ($this->isExpired($now)) {
            return 'expired';
        }

        return 'active';
    }
}
