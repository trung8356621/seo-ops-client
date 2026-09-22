<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Models\User;
use App\Services\Auth\PostLoginRedirector;
use Tests\TestCase;

final class PostLoginRedirectorTest extends TestCase
{
    public function test_staff_goes_to_workspace(): void
    {
        $staff = new User([
            'role' => User::ROLE_STAFF,
            'parent_id' => 1,
        ]);

        self::assertSame(url('/workspace'), app(PostLoginRedirector::class)->urlFor($staff));
    }

    public function test_owner_goes_to_admin(): void
    {
        $owner = new User([
            'role' => User::ROLE_OWNER,
        ]);

        self::assertSame(url('/admin'), app(PostLoginRedirector::class)->urlFor($owner));
    }

    public function test_admin_goes_to_admin(): void
    {
        $admin = new User([
            'role' => User::ROLE_ADMIN,
        ]);

        self::assertSame(url('/admin'), app(PostLoginRedirector::class)->urlFor($admin));
    }
}
