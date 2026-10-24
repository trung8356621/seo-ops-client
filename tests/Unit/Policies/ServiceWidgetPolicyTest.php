<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\User;
use App\Policies\ServiceWidgetPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ServiceWidgetPolicyTest extends TestCase
{
    private function userWithRole(string $role): User
    {
        $user = new User;
        $user->id = 1;
        $user->status = User::STATUS_NORMAL;
        $user->role = $role;

        return $user;
    }

    #[Test]
    public function owner_is_allowed(): void
    {
        $owner = $this->userWithRole(User::ROLE_OWNER);

        self::assertTrue((new ServiceWidgetPolicy)->access($owner));
        self::assertTrue(ServiceWidgetPolicy::allows($owner));
    }

    #[Test]
    #[DataProvider('deniedRolesProvider')]
    public function non_owner_roles_are_denied(string $role): void
    {
        $user = $this->userWithRole($role);

        self::assertFalse((new ServiceWidgetPolicy)->access($user));
        self::assertFalse(ServiceWidgetPolicy::allows($user));
    }

    #[Test]
    public function null_and_non_user_are_denied(): void
    {
        self::assertFalse(ServiceWidgetPolicy::allows(null));
        self::assertFalse(ServiceWidgetPolicy::allows(new \stdClass));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function deniedRolesProvider(): array
    {
        return [
            'staff' => [User::ROLE_STAFF],
            'manager' => [User::ROLE_MANAGER],
            'admin' => [User::ROLE_ADMIN],
        ];
    }
}
