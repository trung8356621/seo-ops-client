<?php

declare(strict_types=1);

namespace App\IndustryContext;

use App\Models\IndustryContextProfile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class IndustryContextProfileManager
{
    public function active(string $key): ?IndustryContextProfile
    {
        return IndustryContextProfile::query()->where('key', $key)->where('is_active', true)->first();
    }

    /** @return Collection<int, IndustryContextProfile> */
    public function revisions(string $key): Collection
    {
        return IndustryContextProfile::query()->where('key', $key)->orderByDesc('created_at')->orderByDesc('id')->limit(3)->get();
    }

    /** @param array<string, mixed> $context */
    public function createInitial(string $key, string $name, array $context): IndustryContextProfile
    {
        return DB::connection($this->connection())->transaction(function () use ($key, $name, $context): IndustryContextProfile {
            if (IndustryContextProfile::query()->where('key', $key)->exists()) {
                throw new InvalidArgumentException("Industry Context key [{$key}] already exists.");
            }

            return IndustryContextProfile::query()->create([
                'key' => $key, 'name' => $name, 'schema_version' => IndustryContextSchema::VERSION,
                'context_json' => $context, 'is_active' => true,
            ]);
        });
    }

    /** @param array<string, mixed> $context */
    public function createRevision(IndustryContextProfile $profile, array $context, ?string $name = null): IndustryContextProfile
    {
        return DB::connection($this->connection())->transaction(function () use ($profile, $context, $name): IndustryContextProfile {
            $revision = IndustryContextProfile::query()->create([
                'key' => $profile->key, 'name' => $name ?? $profile->name,
                'schema_version' => IndustryContextSchema::VERSION, 'context_json' => $context, 'is_active' => false,
            ]);
            $this->prune($profile->key);

            return $revision;
        });
    }

    public function activate(IndustryContextProfile $profile): IndustryContextProfile
    {
        return DB::connection($this->connection())->transaction(function () use ($profile): IndustryContextProfile {
            IndustryContextProfile::query()->where('key', $profile->key)->whereKeyNot($profile->getKey())->update(['is_active' => false]);
            $profile->forceFill(['is_active' => true])->save();
            $this->prune($profile->key);

            return $profile->refresh();
        });
    }

    public function prune(string $key): void
    {
        $excess = IndustryContextProfile::query()->where('key', $key)->count() - 3;
        if ($excess <= 0) {
            return;
        }

        $ids = IndustryContextProfile::query()->where('key', $key)->where('is_active', false)
            ->orderBy('created_at')->orderBy('id')->limit($excess)->pluck('id');
        IndustryContextProfile::query()->whereIn('id', $ids)->delete();
    }

    private function connection(): string
    {
        return (new IndustryContextProfile)->getConnectionName() ?: config('database.default');
    }
}
