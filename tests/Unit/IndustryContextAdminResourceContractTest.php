<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class IndustryContextAdminResourceContractTest extends TestCase
{
    public function test_admin_uses_logical_list_and_exposes_owned_actions(): void
    {
        $resource = file_get_contents(dirname(__DIR__, 2).'/app/Filament/Resources/IndustryContextProfileResource.php');
        self::assertStringContainsString('logicalRepresentatives()', (string) $resource);
        foreach (['quick_generate', 'ViewAction', 'copy_prompt', 'EditAction'] as $action) {
            self::assertStringContainsString($action, (string) $resource);
        }
        self::assertStringContainsString("where('key', \$record->key)->delete()", (string) $resource);
    }

    public function test_create_generates_before_persistence_and_initial_save_uses_manager(): void
    {
        $page = file_get_contents(dirname(__DIR__, 2).'/app/Filament/Resources/IndustryContextProfileResource/Pages/CreateIndustryContextProfile.php');
        self::assertStringContainsString("\$this->data['context_json'] = json_encode", (string) $page);
        self::assertStringContainsString('createInitial(', (string) $page);
    }
}
