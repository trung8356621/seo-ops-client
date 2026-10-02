<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Textarea;

final class MarkdownCodeEditor extends Textarea
{
    protected string $view = 'filament.forms.components.markdown-code-editor';
}
