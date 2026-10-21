<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesCoreDatabaseConnection;
use Illuminate\Database\Eloquent\Model;

class H1CommandReceipt extends Model
{
    use UsesCoreDatabaseConnection;

    protected $table = 'h1_command_receipts';

    protected $fillable = [
        'installation_id',
        'command_id',
        'message_type',
        'payload_hash',
        'result',
        'reason',
        'retryable',
        'quota_overflow_reset',
        'rotation_id',
        'ack_json',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ack_json' => 'array',
            'retryable' => 'boolean',
            'quota_overflow_reset' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }
}
