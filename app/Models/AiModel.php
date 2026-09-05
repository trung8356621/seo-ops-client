<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Canonical AI model inventory (shared Core).
 *
 * Table remains `seo_ai_models` in this phase for compatibility — no rename required.
 */
class AiModel extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_EXHAUSTED = 'exhausted';

    protected $table = 'seo_ai_models';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'capabilities' => 'array',
            'is_hidden' => 'boolean',
        ];
    }

    public function apiConnection(): BelongsTo
    {
        return $this->belongsTo(ApiConnection::class, 'api_connection_id');
    }
}
