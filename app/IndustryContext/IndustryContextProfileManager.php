<?php

declare(strict_types=1);

namespace App\IndustryContext;

use App\Models\IndustryContextProfile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class IndustryContextProfileManager
{
    public function __construct(private readonly IndustryContextFingerprint $fingerprint) {}

    public function active(string $key, string $type = IndustryContextProfile::TYPE_CORE): ?IndustryContextProfile
    {
        return IndustryContextProfile::query()->where('key', $key)->where('type', $type)->where('is_active', true)->first();
    }

    /** @return Collection<int, IndustryContextProfile> */
    public function revisions(string $key, string $type = IndustryContextProfile::TYPE_CORE): Collection
    {
        return IndustryContextProfile::query()->where('key', $key)->where('type', $type)->orderByDesc('created_at')->orderByDesc('id')->limit(3)->get();
    }

    /** @param array<string, mixed> $context */
    public function createInitial(string $key, string $name, array $context, mixed $expiresAt = null): IndustryContextProfile
    {
        return DB::connection($this->connection())->transaction(function () use ($key, $name, $context, $expiresAt): IndustryContextProfile {
            if (IndustryContextProfile::query()->where('key', $key)->exists()) {
                throw new InvalidArgumentException("Industry Context key [{$key}] already exists.");
            }

            return IndustryContextProfile::query()->create([
                'key' => $key, 'name' => $name, 'type' => IndustryContextProfile::TYPE_CORE, 'schema_version' => IndustryContextSchema::VERSION,
                'context_json' => $context, 'is_active' => true, 'expires_at' => $expiresAt,
                'source_core_id' => null, 'source_core_hash' => null,
            ]);
        });
    }

    /** @param array<string, mixed> $context */
    public function createRevision(IndustryContextProfile $profile, array $context, ?string $name = null, mixed $expiresAt = null): IndustryContextProfile
    {
        if ($profile->type !== IndustryContextProfile::TYPE_CORE) {
            throw new InvalidArgumentException('Core revisions can only be created from a Core Industry Context profile.');
        }

        return DB::connection($this->connection())->transaction(function () use ($profile, $context, $name, $expiresAt): IndustryContextProfile {
            $revision = IndustryContextProfile::query()->create([
                'key' => $profile->key, 'name' => $name ?? $profile->name,
                'type' => IndustryContextProfile::TYPE_CORE, 'schema_version' => IndustryContextSchema::VERSION,
                'context_json' => $context, 'is_active' => false, 'expires_at' => $expiresAt,
                'source_core_id' => null, 'source_core_hash' => null,
            ]);
            $this->prune($profile->key, IndustryContextProfile::TYPE_CORE);

            return $revision;
        });
    }

    /** @param array<string, mixed> $context */
    public function createAuxiliaryRevision(string $key, string $type, array $context, mixed $expiresAt = null): IndustryContextProfile
    {
        if (! in_array($type, [IndustryContextProfile::TYPE_DISCOVERY, IndustryContextProfile::TYPE_BREAKOUT], true)) {
            throw new InvalidArgumentException('Auxiliary revisions must be discovery or breakout.');
        }

        return DB::connection($this->connection())->transaction(function () use ($key, $type, $context, $expiresAt): IndustryContextProfile {
            $core = $this->active($key, IndustryContextProfile::TYPE_CORE);
            if ($core === null) {
                throw new InvalidArgumentException("An active Core Industry Context is required for key [{$key}].");
            }

            IndustryAuxiliarySchema::validatedOutput($type, $context);
            $revision = IndustryContextProfile::query()->create([
                'key' => $key,
                'name' => $core->name,
                'type' => $type,
                'schema_version' => IndustryAuxiliarySchema::VERSION,
                'context_json' => $context,
                'is_active' => false,
                'expires_at' => $expiresAt,
                'source_core_id' => $core->getKey(),
                'source_core_hash' => $this->fingerprint->hash((array) $core->context_json),
            ]);
            $this->prune($key, $type);

            return $revision;
        });
    }

    public function activate(IndustryContextProfile $profile): IndustryContextProfile
    {
        return DB::connection($this->connection())->transaction(function () use ($profile): IndustryContextProfile {
            IndustryContextProfile::query()->where('key', $profile->key)->where('type', $profile->type)->whereKeyNot($profile->getKey())->update(['is_active' => false]);
            $profile->forceFill(['is_active' => true])->save();
            $this->prune($profile->key, $profile->type);

            return $profile->refresh();
        });
    }

    public function prune(string $key, string $type = IndustryContextProfile::TYPE_CORE): void
    {
        $branch = IndustryContextProfile::query()->where('key', $key)->where('type', $type);
        $excess = (clone $branch)->count() - 3;
        if ($excess <= 0) {
            return;
        }

        $ids = $branch->where('is_active', false)
            ->orderBy('created_at')->orderBy('id')->limit($excess)->pluck('id');
        IndustryContextProfile::query()->whereIn('id', $ids)->delete();
    }

    public function isStale(IndustryContextProfile $profile): bool
    {
        if ($profile->type === IndustryContextProfile::TYPE_CORE) {
            return false;
        }
        if (! in_array($profile->type, [IndustryContextProfile::TYPE_DISCOVERY, IndustryContextProfile::TYPE_BREAKOUT], true)) {
            throw new InvalidArgumentException("Unknown Industry Context type [{$profile->type}].");
        }

        $core = $this->active($profile->key, IndustryContextProfile::TYPE_CORE);

        return $core === null || ! hash_equals(
            (string) $profile->source_core_hash,
            $this->fingerprint->hash((array) $core->context_json),
        );
    }

    private function connection(): string
    {
        return (new IndustryContextProfile)->getConnectionName() ?: config('database.default');
    }
}
