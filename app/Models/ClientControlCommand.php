<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClientControlCommandStatus;
use App\Models\Concerns\UsesCoreDatabaseConnection;
use Illuminate\Database\Eloquent\Model;

class ClientControlCommand extends Model
{
    use UsesCoreDatabaseConnection;

    protected $fillable = [
        'command_id',
        'command',
        'payload_hash',
        'status',
        'result',
        'error',
        'received_at',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ClientControlCommandStatus::class,
            'result' => 'array',
            'received_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
