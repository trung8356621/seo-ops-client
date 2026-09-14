<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use App\Services\Users\UserHierarchyService;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use Tests\TestCase;

final class UserHierarchyServiceTest extends TestCase
{
    public function test_user_model_core_roles_are_owner_and_staff(): void
    {
        self::assertSame('owner', User::ROLE_OWNER);
        self::assertSame('staff', User::ROLE_STAFF);

        $reflection = new ReflectionClass(User::class);
        self::assertTrue($reflection->hasMethod('owner'));
        self::assertTrue($reflection->hasMethod('teamStaff'));
        self::assertTrue($reflection->hasMethod('accountOwnerId'));

        $fillable = (new User)->getFillable();
        self::assertContains('parent_id', $fillable);
        self::assertContains('name', $fillable);
    }

    public function test_hierarchy_service_clears_links_for_owner(): void
    {
        $service = new UserHierarchyService;

        $ownerData = $service->normalizeFormData([
            'role' => User::ROLE_OWNER,
            'parent_id' => 99,
            'manager_id' => 88,
            'name' => 'Owner',
            'email' => 'o@example.com',
        ], actor: null);

        self::assertNull($ownerData['parent_id']);
        self::assertNull($ownerData['manager_id']);
    }

    public function test_legacy_admin_role_is_invalid_for_hierarchy(): void
    {
        $service = new UserHierarchyService;

        $this->expectException(ValidationException::class);
        $service->normalizeFormData([
            'role' => User::ROLE_ADMIN,
            'parent_id' => 99,
            'manager_id' => 88,
            'name' => 'Admin',
            'email' => 'a@example.com',
        ], actor: null);
    }

    public function test_legacy_manager_role_is_normalized_to_staff(): void
    {
        $service = new UserHierarchyService;

        $data = $service->normalizeFormData([
            'role' => User::ROLE_MANAGER,
            'parent_id' => null,
            'name' => 'Mgr',
            'email' => 'm@example.com',
        ], actor: null);

        self::assertSame(User::ROLE_STAFF, $data['role']);
        self::assertNull($data['manager_id']);
    }

    public function test_staff_may_exist_without_owner(): void
    {
        $service = new UserHierarchyService;

        $data = $service->normalizeFormData([
            'role' => User::ROLE_STAFF,
            'parent_id' => null,
            'name' => 'Solo',
            'email' => 'solo@example.com',
        ], actor: null);

        self::assertSame(User::ROLE_STAFF, $data['role']);
        self::assertNull($data['parent_id']);
        self::assertNull($data['manager_id']);
    }

    public function test_user_resource_has_no_org_manager_field(): void
    {
        $path = dirname(__DIR__, 2).'/app/Filament/Resources/UserResource.php';
        $source = (string) file_get_contents($path);
        self::assertStringNotContainsString("Select::make('manager_id')", $source);
        self::assertStringContainsString('Chủ tài khoản', $source);
        self::assertStringContainsString('UserHierarchyService', $source);
        self::assertStringContainsString("'roles'", $source);
    }

    public function test_admin_panel_uses_full_content_width(): void
    {
        $path = dirname(__DIR__, 2).'/app/Providers/Filament/AdminPanelProvider.php';
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('maxContentWidth', $source);
        self::assertStringContainsString('MaxWidth::Full', $source);
    }
}
