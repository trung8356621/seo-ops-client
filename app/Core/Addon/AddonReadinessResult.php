<?php

declare(strict_types=1);

namespace App\Core\Addon;

/**
 * Immutable value object holding the outcome of an addon readiness validation.
 */
final class AddonReadinessResult
{
    /**
     * @param  list<string>  $diagnostics
     */
    public function __construct(
        public readonly bool $ready,
        public readonly string $slug,
        public readonly string $reason = '',
        public readonly array $diagnostics = [],
    ) {}

    public static function ready(string $slug): self
    {
        return new self(
            ready: true,
            slug: $slug,
            reason: '',
            diagnostics: [],
        );
    }

    /**
     * @param  list<string>  $diagnostics
     */
    public static function notReady(string $slug, string $reason, array $diagnostics = []): self
    {
        return new self(
            ready: false,
            slug: $slug,
            reason: $reason,
            diagnostics: $diagnostics,
        );
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function diagnostic(): string
    {
        if ($this->ready) {
            return "Addon [{$this->slug}] is deployment-ready.";
        }

        $diag = empty($this->diagnostics) ? '' : ' ('.implode('; ', $this->diagnostics).')';

        return "Addon [{$this->slug}] not ready: {$this->reason}{$diag}";
    }
}
