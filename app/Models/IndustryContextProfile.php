<?php

declare(strict_types=1);

namespace App\Models;

use App\IndustryContext\IndustryContextSchema;
use App\Models\Concerns\UsesCoreDatabaseConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class IndustryContextProfile extends Model
{
    use UsesCoreDatabaseConnection;

    protected $fillable = ['key', 'name', 'schema_version', 'context_json', 'is_active', 'expires_at'];

    protected $casts = ['context_json' => 'array', 'is_active' => 'boolean', 'expires_at' => 'datetime'];

    public function scopeLogicalRepresentatives(Builder $query): Builder
    {
        return $query->whereNotExists(function ($subquery): void {
            $subquery->selectRaw('1')
                ->from('industry_context_profiles as preferred')
                ->whereColumn('preferred.key', 'industry_context_profiles.key')
                ->where(function ($better): void {
                    $better->whereColumn('preferred.is_active', '>', 'industry_context_profiles.is_active')
                        ->orWhere(function ($sameStatus): void {
                            $sameStatus->whereColumn('preferred.is_active', 'industry_context_profiles.is_active')
                                ->where(function ($newer): void {
                                    $newer->whereColumn('preferred.created_at', '>', 'industry_context_profiles.created_at')
                                        ->orWhere(function ($sameTime): void {
                                            $sameTime->whereColumn('preferred.created_at', 'industry_context_profiles.created_at')
                                                ->whereColumn('preferred.id', '>', 'industry_context_profiles.id');
                                        });
                                });
                        });
                });
        });
    }

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
