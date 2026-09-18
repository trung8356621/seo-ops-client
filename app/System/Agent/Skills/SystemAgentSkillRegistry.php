<?php

declare(strict_types=1);

namespace App\System\Agent\Skills;

use App\System\Agent\Contracts\AgentSkillContributor;
use InvalidArgumentException;

/**
 * System Agent skill registry (capability-keyed). Domain catalogs register at boot.
 */
final class SystemAgentSkillRegistry
{
    /** @var list<AgentSkillContributor> */
    private array $contributors = [];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $byKey = null;

    public function registerContributor(AgentSkillContributor $contributor): void
    {
        $this->contributors[] = $contributor;
        $this->byKey = null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_values($this->boot());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        return $this->boot()[trim($key)] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->boot()[trim($key)]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->boot());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function boot(): array
    {
        if ($this->byKey !== null) {
            return $this->byKey;
        }

        $map = [];
        foreach ($this->contributors as $contributor) {
            foreach ($contributor->skills() as $skill) {
                $key = trim((string) ($skill['key'] ?? ''));
                if ($key === '') {
                    throw new InvalidArgumentException('Skill key must not be empty.');
                }
                if (isset($map[$key])) {
                    throw new InvalidArgumentException("Skill [{$key}] already registered.");
                }
                $skill['owner'] = $skill['owner'] ?? $contributor->ownerSlug();
                $map[$key] = $skill;
            }
        }

        return $this->byKey = $map;
    }
}
