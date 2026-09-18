<?php

declare(strict_types=1);

namespace App\System\Agent\Contracts;

/**
 * Domain addons register skills via this contributor — System Agent never imports domain skill catalogs.
 *
 * @phpstan-type SkillDefinition array{
 *     key: string,
 *     label?: string,
 *     capability?: string,
 *     owner?: string,
 *     description?: string,
 *     hidden?: bool
 * }
 */
interface AgentSkillContributor
{
    public function ownerSlug(): string;

    /**
     * @return list<SkillDefinition>
     */
    public function skills(): array;
}
