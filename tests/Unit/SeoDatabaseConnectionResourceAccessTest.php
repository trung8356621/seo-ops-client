<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Resources\SeoDatabaseConnectionResource;
use App\Filament\Support\SeoDatabaseConnectionAccess;
use App\Models\SeoDatabaseConnection;
use App\Models\User;
use Tests\TestCase;

/**
 * Legacy SEO Database Connection Filament resource is redirect-only;
 * must not require / query the retired credential table.
 */
final class SeoDatabaseConnectionResourceAccessTest extends TestCase
{
    public function test_resource_is_retired_redirect_shell(): void
    {
        self::assertFalse(SeoDatabaseConnectionResource::shouldRegisterNavigation());
        self::assertFalse(SeoDatabaseConnectionResource::canCreate());

        $connection = new SeoDatabaseConnection([
            'name' => 'Any',
            'type' => 'manual',
            'database' => 'omi_seo_ai',
            'is_active' => true,
        ]);
        $connection->id = 1;

        self::assertFalse(SeoDatabaseConnectionResource::canEdit($connection));
        self::assertFalse(SeoDatabaseConnectionResource::canDelete($connection));

        $list = (string) file_get_contents(
            app_path('Filament/Resources/SeoDatabaseConnectionResource/Pages/ListSeoDatabaseConnections.php'),
        );
        self::assertStringContainsString("ServiceConfigure::getUrl(['service' => 'seo']", $list);

        $src = (string) file_get_contents(
            (string) (new \ReflectionClass(SeoDatabaseConnectionResource::class))->getFileName(),
        );
        self::assertStringContainsString("whereRaw('0 = 1')", $src);
        self::assertStringNotContainsString('SeoDatabaseConnection::query(', $src);
    }

    public function test_access_helper_does_not_query_legacy_table_for_owner_has_connection(): void
    {
        $src = (string) file_get_contents(
            (string) (new \ReflectionClass(SeoDatabaseConnectionAccess::class))->getFileName(),
        );
        self::assertStringNotContainsString('SeoDatabaseConnection::query(', $src);
        self::assertStringContainsString('ServiceDatabaseConnectionResolver', $src);
    }

    public function test_admin_can_access_redirect_shell_without_legacy_table(): void
    {
        $admin = new User(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin);

        self::assertTrue(SeoDatabaseConnectionResource::canAccess());
        self::assertFalse(SeoDatabaseConnectionResource::canCreate());
    }
}
