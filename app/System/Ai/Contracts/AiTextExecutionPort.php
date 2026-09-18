<?php

declare(strict_types=1);

namespace App\System\Ai\Contracts;

/**
 * Port implemented by addons (ai-prompt) — System never imports PromptRunner/Canonical classes.
 *
 * @phpstan-type TextExecOptions array<string, mixed>
 * @phpstan-type TextExecResult array{
 *     text: string,
 *     usage?: array<string, mixed>|null,
 *     provider?: string|null,
 *     model?: string|null,
 *     physical_route?: string|null,
 *     trace?: array<string, mixed>
 * }
 */
interface AiTextExecutionPort
{
    /**
     * @param  TextExecOptions  $options
     * @return TextExecResult
     */
    public function generate(
        string $compiledPrompt,
        string $hookKey,
        array $options = [],
    ): array;
}
