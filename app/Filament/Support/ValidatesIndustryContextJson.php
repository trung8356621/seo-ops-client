<?php

declare(strict_types=1);

namespace App\Filament\Support;

trait ValidatesIndustryContextJson
{
    public function validateIndustryContextJson(): void
    {
        $this->validateOnly('data.context_json');
    }
}
