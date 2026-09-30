<?php

declare(strict_types=1);

namespace App\Models;

use App\IndustryContext\IndustryContextSchema;
use App\Models\Concerns\UsesCoreDatabaseConnection;
use Illuminate\Database\Eloquent\Model;

final class IndustryContextProfile extends Model
{
    use UsesCoreDatabaseConnection;

    protected $fillable = ['key', 'name', 'schema_version', 'context_json'];

    protected $casts = ['context_json' => 'array'];

    protected static function booted(): void
    {
        self::saving(function (self $profile): void {
            IndustryContextSchema::assertValid((array) $profile->context_json);
            if ($profile->schema_version !== IndustryContextSchema::VERSION) {
                throw new \UnexpectedValueException('Profile schema_version must be '.IndustryContextSchema::VERSION.'.');
            }
        });
    }
}
