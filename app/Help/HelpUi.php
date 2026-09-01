<?php

declare(strict_types=1);

namespace App\Help;

use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Support\Enums\ActionSize;
use Filament\Support\Enums\IconSize;

/**
 * Filament helpers to open contextual Help without Livewire round-trip.
 *
 * Field:  ->hintAction(HelpUi::fieldHintAction(...))
 * Section: ->headerActions([HelpUi::fieldHintAction(...)])  // Section has no hintAction
 *
 * Visual: secondary icon-only affordance (ghost/neutral) — not a primary action.
 */
final class HelpUi
{
    public static function fieldHintAction(
        string $contextKey,
        ?string $tooltip = null,
        ?string $actionSuffix = null,
    ): FormAction {
        $key = trim($contextKey);
        $tooltip ??= 'Help';
        $suffix = $actionSuffix !== null && $actionSuffix !== ''
            ? '_'.preg_replace('/[^a-zA-Z0-9_]+/', '_', $actionSuffix)
            : '';
        $actionName = 'help_'.str_replace(['.', '-'], '_', $key).$suffix;

        return FormAction::make($actionName)
            ->icon('heroicon-m-question-mark-circle')
            ->iconButton()
            ->color('gray')
            ->size(ActionSize::Small)
            ->iconSize(IconSize::Small)
            ->tooltip($tooltip)
            ->label('')
            ->extraAttributes([
                'class' => 'seo-context-help-btn',
                'aria-label' => $tooltip,
            ])
            ->alpineClickHandler(
                'window.dispatchEvent(new CustomEvent(\'seo-global-help:open\', {'
                .' detail: { contextKey: '.json_encode($key).', trigger: $el }'
                .' }));'
            );
    }

    /**
     * @return list<FormAction>
     */
    public static function sectionHeaderActions(
        string $contextKey,
        ?string $tooltip = null,
        ?string $actionSuffix = null,
    ): array {
        return [self::fieldHintAction($contextKey, $tooltip, $actionSuffix)];
    }
}
