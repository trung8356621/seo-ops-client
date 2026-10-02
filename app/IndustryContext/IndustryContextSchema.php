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

    /**
     * @param  list<string>  $market
     * @return array<string, mixed>
     */
    public static function template(?string $name = null, ?string $key = null, string $language = 'vi', array $market = ['VN']): array
    {
        return [
            'schema_version' => self::VERSION,
            'identity' => [
                'context_name' => $name ?? '',
                'context_slug' => $key ?? '',
                'language' => $language,
                'market' => $market,
            ],
            'industry_taxonomy' => ['hierarchy' => [], 'industry_keywords' => []],
            'business' => [
                'business_models' => [],
                'sales_models' => [],
                'customer_relationships' => [],
                'primary_revenue_sources' => [],
                'typical_order_value' => null,
                'sales_cycle' => null,
                'purchase_frequency' => null,
            ],
            'audiences' => [],
            'offerings' => ['product_families' => [], 'adjacent_products' => [], 'substitutes' => []],
            'customer_needs' => ['jobs_to_be_done' => [], 'pain_points' => [], 'questions' => [], 'decision_factors' => []],
            'demand_drivers' => [],
            'purchase_behavior' => ['journey_stages' => [], 'channels' => [], 'influencers' => []],
            'seasonality' => ['peak_periods' => [], 'low_periods' => [], 'seasonal_factors' => []],
            'market_context' => ['macro_factors' => [], 'regulatory_factors' => [], 'competitive_landscape' => []],
            'trend_sensitivity' => ['emerging_trends' => [], 'fading_trends' => []],
            'content_universe' => ['content_territories' => [], 'authority_topics' => []],
            'search_behavior' => ['core_search_patterns' => [], 'modifier_patterns' => []],
            'content_boundaries' => ['in_scope' => [], 'out_of_scope' => []],
        ];
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
        foreach (['topic_mix', 'discovery_attention_topics', 'breakout_topics'] as $retired) {
            if (is_array($context['content_universe'] ?? null) && array_key_exists($retired, $context['content_universe'])) {
                $errors[] = "Unknown content_universe field [{$retired}].";
            }
        }
        if (is_array($context['content_boundaries'] ?? null) && array_key_exists('tier_rules', $context['content_boundaries'])) {
            $errors[] = 'Unknown content_boundaries field [tier_rules].';
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
