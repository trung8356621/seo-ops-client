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
        'paid_lock_reasons' => 'array',
        'metadata' => 'array',
        'balance' => 'float',
        'balance_warning_threshold' => 'float',
        'balance_checked_at' => 'datetime',
    ];

    public function isBalanceSupported(): bool
    {
        return (string) $this->getAttribute('balance_status') !== 'unsupported';
    }

    public function isLowBalance(): bool
    {
        return (string) $this->getAttribute('balance_status') === 'low_balance';
    }

    public function isCheckFailed(): bool
    {
        return (string) $this->getAttribute('balance_status') === 'check_failed';
    }

    public function formattedBalance(): string
    {
        if ($this->getAttribute('balance') === null) {
            return '—';
        }

        $currency = (string) ($this->getAttribute('currency') ?: 'USD');
        $sym = $currency === 'USD' ? '$' : $currency . ' ';

        return $sym . number_format((float) $this->getAttribute('balance'), 2);
    }

    /**
     * Authoritative paid-lane lock (api_connections.paid_locked).
     * Reasons live in paid_lock_reasons; free routes ignore this flag.
     * Runtime health must not own a parallel paid-lock authority.
     */
    public function isPaidLocked(): bool
    {
        return (bool) ($this->getAttribute('paid_locked') ?? false);
    }

    /**
     * @return list<string>
     */
    public function paidLockReasons(): array
    {
        $raw = $this->getAttribute('paid_lock_reasons');
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && $item !== '' && ! in_array($item, $out, true)) {
                $out[] = $item;
            }
        }

        return $out;
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
