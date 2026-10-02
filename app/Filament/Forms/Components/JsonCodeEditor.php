<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\Textarea;

final class JsonCodeEditor extends Textarea
{
    protected string $view = 'filament.forms.components.json-code-editor';

    protected string|Closure $schemaType = 'core';

    public function schemaType(string|Closure $type): static
    {
        $this->schemaType = $type;

        return $this;
    }

    public function getSchemaType(): string
    {
        return (string) $this->evaluate($this->schemaType);
    }
}
