<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Tests\TestCase;

final class RoleAxesMatrixTest extends TestCase
{
    public function test_owner_with_content_manager_seo_role_keeps_axes_independent(): void
    {
        $user = new User([
            'role' => User::ROLE_OWNER,
            'seo_role' => User::SEO_ROLE_CONTENT_MANAGER,
            'status' => User::STATUS_NORMAL,
        ]);

        $this->actingAs($user);

        $this->assertTrue($user->canAccessPanel(filament()->getPanel('admin')));
        $this->assertTrue(SeoAccessControl::canAccessSeoPanel($user));
        $this->assertSame(SeoAccessControl::ROLE_CONTENT_MANAGER, SeoAccessControl::actualRole());
        $this->assertFalse(SeoAccessControl::canAccessManagerFeatures());
    }

    public function test_staff_with_manager_seo_role_denied_admin_allowed_seo_manager(): void
    {
        $user = new User([
            'role' => User::ROLE_STAFF,
            'seo_role' => User::SEO_ROLE_MANAGER,
            'status' => User::STATUS_NORMAL,
            'parent_id' => 1,
        ]);

        $this->actingAs($user);

        $this->assertFalse($user->canAccessPanel(filament()->getPanel('admin')));
        $this->assertTrue(SeoAccessControl::canAccessSeoPanel($user));
        $this->assertSame(SeoAccessControl::ROLE_MANAGER, SeoAccessControl::actualRole());
        $this->assertTrue(SeoAccessControl::canAccessManagerFeatures());
    }

    public function test_legacy_admin_role_can_access_admin_but_not_seo_without_owner_link(): void
    {
        $user = new User([
            'role' => User::ROLE_ADMIN,
            'seo_role' => User::SEO_ROLE_MANAGER,
            'status' => User::STATUS_NORMAL,
        ]);

        $this->actingAs($user);

        $this->assertTrue($user->canAccessPanel(filament()->getPanel('admin')));
        $this->assertFalse(SeoAccessControl::canAccessSeoPanel($user));
    }
}
