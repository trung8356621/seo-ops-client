<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\IndustryContext\IndustryAuxiliarySchema;
use App\IndustryContext\IndustryContextSchema;
use JsonException;
use Throwable;

trait ValidatesIndustryContextJson
{
    /** @return array{valid: bool, message: string|null} */
    public function validateIndustryContextJson(string $raw, string $type = 'core'): array
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return ['valid' => false, 'message' => $exception->getMessage()];
        }

        try {
            if ($type === 'core') {
                $errors = IndustryContextSchema::validate($decoded);

                return ['valid' => $errors === [], 'message' => $errors === [] ? null : implode(' ', $errors)];
            }

            IndustryAuxiliarySchema::validatedOutput($type, $decoded);

            return ['valid' => true, 'message' => null];
        } catch (Throwable $exception) {
            return ['valid' => false, 'message' => $exception->getMessage()];
        }
    }
}
