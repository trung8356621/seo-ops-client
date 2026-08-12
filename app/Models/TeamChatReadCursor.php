<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesCoreDatabaseConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamChatReadCursor extends Model
{
    use UsesCoreDatabaseConnection;

    protected $fillable = [
        'owner_id',
        'user_id',
        'last_read_message_id',
    ];

    protected $casts = [
        'owner_id' => 'integer',
        'user_id' => 'integer',
        'last_read_message_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
