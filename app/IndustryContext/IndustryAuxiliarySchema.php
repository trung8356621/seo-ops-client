<?php

declare(strict_types=1);

namespace App\IndustryContext;

use JsonException;
use RuntimeException;
use UnexpectedValueException;

final class IndustryAuxiliarySchema
{
    public const VERSION = '1.0';

    public const DISCOVERY = 'discovery';

    public const BREAKOUT = 'breakout';

    public static function json(string $type): string
    {
        $filename = match ($type) {
            self::DISCOVERY => 'industry-discovery.v1.schema.json',
            self::BREAKOUT => 'industry-breakout.v1.schema.json',
            default => throw new RuntimeException("Unknown Industry Context prompt type [{$type}]."),
        };
        $contents = file_get_contents(resource_path('schemas/'.$filename));
        if ($contents === false || trim($contents) === '') {
            throw new RuntimeException("Canonical schema [{$filename}] is missing.");
        }

        return $contents;
    }

    /** @return array<string, mixed> */
    public static function validatedOutput(string $type, mixed $output): array
    {
        if (! in_array($type, [self::DISCOVERY, self::BREAKOUT], true)) {
            throw new UnexpectedValueException("Unknown auxiliary Industry Context type [{$type}].");
        }

        if (is_string($output)) {
            try {
                $output = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new UnexpectedValueException('Generation returned invalid JSON.', 0, $exception);
            }
        }
        if (! is_array($output) || array_is_list($output) || ($output['schema_version'] ?? null) !== self::VERSION || ! is_array($output['items'] ?? null) || ! array_is_list($output['items'])) {
            throw new UnexpectedValueException('Generation output does not match the auxiliary Industry Context contract.');
        }
        $required = $type === self::DISCOVERY
            ? ['id', 'topic', 'keywords', 'attention_reason', 'bridge']
            : ['id', 'topic', 'attention_angle', 'possible_bridges', 'keywords'];
        foreach ($output['items'] as $item) {
            if (! is_array($item) || array_is_list($item) || array_diff($required, array_keys($item)) !== []) {
                throw new UnexpectedValueException('Generation output contains an invalid auxiliary Industry Context item.');
            }
            if (! is_array($item['keywords']) || ! array_is_list($item['keywords'])) {
                throw new UnexpectedValueException('Generation output item keywords must be an array.');
            }
            if ($type === self::DISCOVERY && (! is_array($item['bridge']) || ! is_array($item['bridge']['refs'] ?? null) || ($item['bridge']['refs'] ?? []) === [])) {
                throw new UnexpectedValueException('Discovery output items require a Core bridge with refs.');
            }
            if ($type === self::BREAKOUT && (! is_array($item['possible_bridges']) || ! array_is_list($item['possible_bridges']))) {
                throw new UnexpectedValueException('Breakout output item possible_bridges must be an array.');
            }
        }

        return $output;
    }
}
