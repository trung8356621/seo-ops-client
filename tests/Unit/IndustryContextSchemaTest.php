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
    public function v1_schema_exposes_topic_mix_and_tiered_topic_territories(): void
    {
        $schema = IndustryContextSchema::schema();
        $universe = $schema['properties']['content_universe']['properties'];
        self::assertSame(['core' => 60, 'discovery_attention' => 30, 'breakout' => 10], $universe['topic_mix']['default']);
        self::assertSame(100, array_sum($universe['topic_mix']['default']));
        self::assertSame('#/$defs/discoveryAttentionTopic', $universe['discovery_attention_topics']['items']['$ref']);
        self::assertSame('#/$defs/breakoutTopic', $universe['breakout_topics']['items']['$ref']);
        self::assertStringContainsString('never an article title', $schema['$defs']['discoveryAttentionTopic']['properties']['name']['description']);
        self::assertSame('far', $schema['$defs']['breakoutTopic']['properties']['context_distance']['const']);
        self::assertFalse($schema['$defs']['breakoutBoundary']['properties']['require_context_bridge']['const']);
        self::assertTrue($schema['$defs']['breakoutBoundary']['properties']['prefer_possible_bridge']['const']);
    }

    #[Test]
    public function topic_mix_must_total_one_hundred_when_present(): void
    {
        $context = $this->validContext();
        $context['content_universe'] = ['topic_mix' => ['core' => 60, 'discovery_attention' => 30, 'breakout' => 10]];
        self::assertSame([], IndustryContextSchema::validate($context));

        $context['content_universe']['topic_mix']['breakout'] = 9;
        self::assertStringContainsString('weights must total 100', implode(' ', IndustryContextSchema::validate($context)));
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
