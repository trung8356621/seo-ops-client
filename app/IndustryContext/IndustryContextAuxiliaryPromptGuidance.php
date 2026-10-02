<?php

declare(strict_types=1);

namespace App\IndustryContext;

use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\IndustryContextAuxiliaryPromptGuidance as BaseGuidance;

final class IndustryContextAuxiliaryPromptGuidance
{
    public const HEADING = BaseGuidance::HEADING;

    public static function guidance(): string
    {
        return BaseGuidance::guidance();
    }

    public static function appliesTo(string $typeOrHook): bool
    {
        return BaseGuidance::appliesTo($typeOrHook);
    }
}
