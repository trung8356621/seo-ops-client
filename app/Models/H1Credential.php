<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesCoreDatabaseConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class H1Credential extends Model
{
    use UsesCoreDatabaseConnection;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRING = 'retiring';

    public const STATUS_RETIRED = 'retired';

    public const STATUS_PENDING = 'pending';

    protected $table = 'h1_credentials';

    protected $fillable = [
        'installation_id',
        'credential_id',
        'version',
        'secret_encrypted',
        'status',
        'activated_at',
        'retired_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'activated_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    public function setSecretBase64Url(string $secretBase64Url): void
    {
        $this->secret_encrypted = Crypt::encryptString($secretBase64Url);
    }

    public function secretBase64Url(): string
    {
        return Crypt::decryptString((string) $this->secret_encrypted);
    }
}
