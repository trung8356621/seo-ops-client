<?php

declare(strict_types=1);

namespace App\IndustryContext;

use JsonException;
use RuntimeException;

final class IndustryContextFingerprint
{
    /** @param array<string, mixed> $context */
    public function hash(array $context): string
    {
        try {
            $json = json_encode(
                $this->canonicalize($context),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Industry Context could not be fingerprinted.', 0, $exception);
        }

        return hash('sha256', $json);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
