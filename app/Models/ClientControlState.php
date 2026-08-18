<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClientControlStatus;
use App\Models\Concerns\UsesCoreDatabaseConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ClientControlState extends Model
{
    use UsesCoreDatabaseConnection;

    protected $table = 'client_control_state';

    protected $fillable = [
        'installation_id',
        'control_server_url',
        'installation_secret',
        'status',
        'services_revision',
        'client_version',
        'locked_at',
        'last_command_at',
        'last_command_id',
        'connected_at',
    ];

    protected $hidden = [
        'installation_secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'installation_secret' => 'encrypted',
            'status' => ClientControlStatus::class,
            'services_revision' => 'integer',
            'locked_at' => 'datetime',
            'last_command_at' => 'datetime',
            'connected_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return DB::transaction(function (): self {
            $row = self::query()->orderBy('id')->lockForUpdate()->first();
            if ($row instanceof self) {
                return $row;
            }

            return self::query()->create([
                'status' => ClientControlStatus::Unregistered,
                'client_version' => (string) config('client_control.client_version'),
            ]);
        });
    }

    public function isLocked(): bool
    {
        return $this->status->blocksBusinessOperations();
    }
}
