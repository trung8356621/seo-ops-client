<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Models\AiModel;
use App\Models\ApiConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * CASE D + forbidden Core → addon AI model dependency.
 */
final class CoreAiModelOwnershipContractTest extends TestCase
{
    public function test_api_connection_uses_core_ai_model_not_addon_seo_ai_model(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ApiConnection::class))->getFileName()
        );

        self::assertStringNotContainsString(
            'Omnichannel\\Addons\\AiPrompt\\Models\\SeoAiModel',
            $source,
        );
        self::assertStringContainsString('AiModel::class', $source);
        self::assertTrue(method_exists(ApiConnection::class, 'aiModels'));
        self::assertTrue(method_exists(ApiConnection::class, 'seoAiModels'));
    }

    public function test_canonical_ai_model_lives_in_app_models(): void
    {
        self::assertSame('seo_ai_models', (new AiModel)->getTable());
        self::assertTrue(is_subclass_of(
            \Omnichannel\Addons\AiPrompt\Models\SeoAiModel::class,
            AiModel::class,
        ));
    }

    public function test_ai_models_relation_return_type_points_at_core_model(): void
    {
        $method = new ReflectionMethod(ApiConnection::class, 'aiModels');
        $body = file_get_contents($method->getFileName());
        self::assertIsString($body);
        self::assertStringContainsString('hasMany(AiModel::class', $body);
    }
}
