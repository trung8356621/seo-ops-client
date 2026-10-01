<?php

declare(strict_types=1);

namespace App\IndustryContext;

use JsonException;
use RuntimeException;
use UnexpectedValueException;

final class IndustryContextSchema
{
    public const VERSION = '1.0';

    /** @var list<string> */
    public const TOP_LEVEL_KEYS = [
        'schema_version', 'identity', 'industry_taxonomy', 'business', 'audiences',
        'offerings', 'customer_needs', 'demand_drivers', 'purchase_behavior',
        'seasonality', 'market_context', 'trend_sensitivity', 'content_universe',
        'search_behavior', 'content_boundaries',
    ];

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        try {
            $decoded = json_decode(self::json(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Canonical Industry Context schema is invalid JSON.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Canonical Industry Context schema root must be an object.');
        }

        return $decoded;
    }

    public static function json(): string
    {
        $contents = file_get_contents(resource_path('schemas/industry-context.v1.schema.json'));
        if ($contents === false || trim($contents) === '') {
            throw new RuntimeException('Canonical Industry Context schema is missing.');
        }

        return $contents;
    }

    /** @return list<string> */
    public static function validate(mixed $context): array
    {
        if (! is_array($context) || array_is_list($context)) {
            return ['Root must be a JSON object.'];
        }

        $errors = [];
        $unknown = array_diff(array_keys($context), self::TOP_LEVEL_KEYS);
        if ($unknown !== []) {
            $errors[] = 'Unknown top-level fields: '.implode(', ', $unknown).'.';
        }
        foreach (self::TOP_LEVEL_KEYS as $key) {
            if (! array_key_exists($key, $context)) {
                $errors[] = "Missing required top-level field [{$key}].";
            }
        }
        if (($context['schema_version'] ?? null) !== self::VERSION) {
            $errors[] = 'schema_version must be '.self::VERSION.'.';
        }
        foreach (['audiences', 'demand_drivers'] as $key) {
            if (array_key_exists($key, $context) && (! is_array($context[$key]) || ! array_is_list($context[$key]))) {
                $errors[] = "[{$key}] must be an array.";
            }
        }
        foreach (array_diff(self::TOP_LEVEL_KEYS, ['schema_version', 'audiences', 'demand_drivers']) as $key) {
            if (array_key_exists($key, $context) && (! is_array($context[$key]) || array_is_list($context[$key]))) {
                $errors[] = "[{$key}] must be an object.";
            }
        }

        $contentUniverse = $context['content_universe'] ?? null;
        $topicMix = is_array($contentUniverse) ? ($contentUniverse['topic_mix'] ?? null) : null;
        if ($topicMix !== null) {
            if (! is_array($topicMix) || array_is_list($topicMix)) {
                $errors[] = '[content_universe.topic_mix] must be an object.';
            } else {
                $expectedKeys = ['core', 'discovery_attention', 'breakout'];
                if (array_diff(array_keys($topicMix), $expectedKeys) !== [] || array_diff($expectedKeys, array_keys($topicMix)) !== []) {
                    $errors[] = '[content_universe.topic_mix] must contain only core, discovery_attention, and breakout.';
                } elseif (array_filter($topicMix, static fn (mixed $value): bool => ! is_int($value)) !== []) {
                    $errors[] = '[content_universe.topic_mix] weights must be integers.';
                } elseif (array_sum($topicMix) !== 100) {
                    $errors[] = '[content_universe.topic_mix] weights must total 100.';
                }
            }
        }

        return $errors;
    }

    public static function assertValid(mixed $context): void
    {
        $errors = self::validate($context);
        if ($errors !== []) {
            throw new UnexpectedValueException(implode(' ', $errors));
        }
    }
}
