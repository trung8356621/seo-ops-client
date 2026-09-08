<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesCoreDatabaseConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Canonical AI/SEO API credentials — CORE mysql (physical omi_client).
 * UI location (admin Settings / SEO) does not change data ownership.
 */
class ApiConnection extends Model
{
    use UsesCoreDatabaseConnection;

    protected $guarded = [];

    protected $casts = [
        'api_key' => 'encrypted',
        'is_global' => 'boolean',
        'paid_locked' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * User-facing Free only preference (skip paid candidates; free remain eligible).
     * Distinct from inactive status and from runtime health budget locks.
     */
    public function isPaidLocked(): bool
    {
        return (bool) ($this->getAttribute('paid_locked') ?? false);
    }

    public function isActiveConnection(): bool
    {
        return (string) $this->getAttribute('status') === 'active';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function aiModels(): HasMany
    {
        return $this->hasMany(AiModel::class, 'api_connection_id');
    }

    /**
     * @deprecated Use aiModels() — kept as compatibility alias for call sites still naming SEO inventory.
     */
    public function seoAiModels(): HasMany
    {
        return $this->aiModels();
    }
}
