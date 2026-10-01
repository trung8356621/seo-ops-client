<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Textarea;

final class JsonCodeEditor extends Textarea
{
    protected string $view = 'filament.forms.components.json-code-editor';
}
