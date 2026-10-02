<?php

declare(strict_types=1);

namespace App\Models;

use App\IndustryContext\IndustryAuxiliarySchema;
use App\IndustryContext\IndustryContextSchema;
use App\Models\Concerns\UsesCoreDatabaseConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class IndustryContextProfile extends Model
{
    use UsesCoreDatabaseConnection;

    public const TYPE_CORE = 'core';

    public const TYPE_DISCOVERY = IndustryAuxiliarySchema::DISCOVERY;

    public const TYPE_BREAKOUT = IndustryAuxiliarySchema::BREAKOUT;

    protected $attributes = ['type' => self::TYPE_CORE];

    protected $fillable = ['key', 'name', 'type', 'schema_version', 'context_json', 'is_active', 'expires_at', 'source_core_id', 'source_core_hash'];

    protected $casts = ['context_json' => 'array', 'is_active' => 'boolean', 'expires_at' => 'datetime'];

    public function scopeLogicalRepresentatives(Builder $query): Builder
    {
        return $query->where('industry_context_profiles.type', self::TYPE_CORE)->whereNotExists(function ($subquery): void {
            $subquery->selectRaw('1')
                ->from('industry_context_profiles as preferred')
                ->whereColumn('preferred.key', 'industry_context_profiles.key')
                ->where('preferred.type', self::TYPE_CORE)
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
            match ($profile->type) {
                self::TYPE_CORE => IndustryContextSchema::assertValid((array) $profile->context_json),
                self::TYPE_DISCOVERY, self::TYPE_BREAKOUT => IndustryAuxiliarySchema::validatedOutput($profile->type, $profile->context_json),
                default => throw new \UnexpectedValueException("Unknown Industry Context type [{$profile->type}]."),
            };
            if ($profile->schema_version !== IndustryContextSchema::VERSION) {
                throw new \UnexpectedValueException('Profile schema_version must be '.IndustryContextSchema::VERSION.'.');
            }
            if ($profile->type === self::TYPE_CORE && ($profile->source_core_id !== null || $profile->source_core_hash !== null)) {
                throw new \UnexpectedValueException('Core Industry Context profiles cannot contain source Core provenance.');
            }
        });
    }
}
