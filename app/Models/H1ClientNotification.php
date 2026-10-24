<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesCoreDatabaseConnection;
use Illuminate\Database\Eloquent\Model;

class H1ClientNotification extends Model
{
    use UsesCoreDatabaseConnection;

    protected $table = 'h1_client_notifications';

    protected $fillable = [
        'notification_id',
        'installation_id',
        'command_id',
        'action',
        'service_key',
        'message',
        'status_before',
        'status_after',
        'entitlement_revision',
        'issued_at',
        'display_active',
        'superseded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entitlement_revision' => 'integer',
            'issued_at' => 'datetime',
            'display_active' => 'boolean',
            'superseded_at' => 'datetime',
        ];
    }
}
