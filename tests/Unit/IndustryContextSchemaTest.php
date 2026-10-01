<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\IndustryContext\IndustryContextSchema;
use App\Models\IndustryContextProfile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use UnexpectedValueException;

final class IndustryContextSchemaTest extends TestCase
{
    #[Test]
    public function model_and_schema_expose_the_v1_core_contract(): void
    {
        $model = new IndustryContextProfile(['context_json' => $this->validContext()]);
        self::assertSame(config('database.core_connection', 'mysql'), $model->getConnectionName());
        self::assertSame($this->validContext(), $model->context_json);
        $model->is_active = 1;
        self::assertTrue($model->is_active);
        self::assertSame('1.0', IndustryContextSchema::VERSION);
        self::assertSame('https://json-schema.org/draft/2020-12/schema', IndustryContextSchema::schema()['$schema']);
        self::assertSame(IndustryContextSchema::TOP_LEVEL_KEYS, IndustryContextSchema::schema()['required']);
    }

    #[Test]
    public function validator_rejects_malformed_root_unknown_fields_and_version_mismatch(): void
    {
        self::assertNotEmpty(IndustryContextSchema::validate([]));
        self::assertNotEmpty(IndustryContextSchema::validate(['schema_version' => '1.0']));
        $unknown = $this->validContext();
        $unknown['random'] = true;
        self::assertStringContainsString('Unknown top-level fields', implode(' ', IndustryContextSchema::validate($unknown)));
        $wrongVersion = $this->validContext();
        $wrongVersion['schema_version'] = '2.0';
        self::assertStringContainsString('schema_version must be 1.0', implode(' ', IndustryContextSchema::validate($wrongVersion)));
    }

    #[Test]
    public function invalid_context_is_rejected_before_persistence(): void
    {
        $this->expectException(UnexpectedValueException::class);
        (new IndustryContextProfile(['key' => 'bad', 'name' => 'Bad', 'schema_version' => '1.0', 'context_json' => []]))->save();
    }

    #[Test]
    public function migration_allows_revision_rows_and_indexes_active_lookup(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_09_30_100000_create_industry_context_profiles_table.php'));
        self::assertStringContainsString("->string('key')->index()", (string) $migration);
        self::assertStringNotContainsString("->string('key')->unique()", (string) $migration);
        self::assertStringContainsString("->boolean('is_active')->default(false)->index()", (string) $migration);
        self::assertStringContainsString("->index(['key', 'is_active'])", (string) $migration);
        self::assertStringNotContainsString('user_id', (string) $migration);
        self::assertStringNotContainsString('site_id', (string) $migration);
    }

    #[Test]
    public function v1_schema_is_full_core_only_without_retired_lanes_or_weights(): void
    {
        $schema = IndustryContextSchema::schema();
        $universe = $schema['properties']['content_universe']['properties'];
        self::assertSame(
            ['core_topics', 'adjacent_topics', 'lifestyle_topics', 'educational_topics', 'commercial_topics', 'business_topics'],
            array_keys($universe),
        );
        foreach (['topic_mix', 'discovery_attention_topics', 'breakout_topics'] as $removed) {
            self::assertArrayNotHasKey($removed, $universe);
        }
        self::assertArrayNotHasKey('tier_rules', $schema['properties']['content_boundaries']['properties']);
        foreach (['discoveryAttentionTopic', 'breakoutTopic', 'coreBoundary', 'discoveryBoundary', 'breakoutBoundary'] as $removed) {
            self::assertArrayNotHasKey($removed, $schema['$defs']);
        }

        self::assertSame(IndustryContextSchema::TOP_LEVEL_KEYS, $schema['required']);
        self::assertStringNotContainsString('60/30/10', IndustryContextSchema::json());
    }

    #[Test]
    public function schema_distinguishes_short_keywords_from_queries_and_questions(): void
    {
        $schema = IndustryContextSchema::schema();
        $keywords = $schema['properties']['industry_taxonomy']['properties']['industry_keywords']['description'];
        $queries = $schema['properties']['search_behavior']['properties']['query_patterns']['description'];
        $questions = $schema['properties']['customer_needs']['properties']['questions']['description'];

        self::assertStringContainsString('1-4 words', $keywords);
        self::assertStringContainsString('5 words or fewer', $keywords);
        self::assertStringContainsString('shortest phrase', $keywords);
        self::assertStringContainsString('may be longer', $queries);
        self::assertStringContainsString('never be mechanically treated as primary keyword candidates', $queries);
        self::assertStringContainsString('Full natural-language questions are allowed', $questions);
    }

    #[Test]
    public function validation_rejects_retired_nested_fields(): void
    {
        $context = $this->validContext();
        $context['content_universe'] = ['topic_mix' => []];
        $context['content_boundaries'] = ['tier_rules' => []];

        $errors = implode(' ', IndustryContextSchema::validate($context));
        self::assertStringContainsString('Unknown content_universe field [topic_mix]', $errors);
        self::assertStringContainsString('Unknown content_boundaries field [tier_rules]', $errors);
    }

    /** @return array<string,mixed> */
    private function validContext(): array
    {
        $context = array_fill_keys(IndustryContextSchema::TOP_LEVEL_KEYS, []);
        $context['schema_version'] = '1.0';
        foreach (array_diff(IndustryContextSchema::TOP_LEVEL_KEYS, ['schema_version', 'audiences', 'demand_drivers']) as $key) {
            $context[$key] = ['fixture' => null];
        }

        return $context;
    }
}
