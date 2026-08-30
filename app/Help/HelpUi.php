<?php

declare(strict_types=1);

namespace App\Help;

use Filament\Forms\Components\Actions\Action as FormAction;

/**
 * Filament helpers to open contextual Help without Livewire round-trip.
 *
 * Field:  ->hintAction(HelpUi::fieldHintAction(...))
 * Section: ->headerActions([HelpUi::fieldHintAction(...)])  // Section has no hintAction
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
            ->tooltip($tooltip)
            ->label('')
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
