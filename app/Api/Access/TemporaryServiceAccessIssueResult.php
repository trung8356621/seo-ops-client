<?php

declare(strict_types=1);

namespace App\Api\Access;

/**
 * Result of minting a temporary Service access token (raw returned once).
 */
final class TemporaryServiceAccessIssueResult
{
    public function __construct(
        public readonly string $rawToken,
        public readonly string $expiresAt,
        public readonly int $siteId,
        public readonly string $lookupId,
    ) {}

    public function siteRef(): string
    {
        return 'site:'.$this->siteId;
    }
}
