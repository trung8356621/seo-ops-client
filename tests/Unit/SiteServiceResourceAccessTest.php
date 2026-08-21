<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Resources\SiteServiceResource;
use App\Models\SiteService;
use App\Models\User;
use Tests\TestCase;

final class SiteServiceResourceAccessTest extends TestCase
{
    public function test_legacy_admin_cannot_access_site_service_crud(): void
    {
        $this->actingAs($this->userWithRole(User::ROLE_ADMIN));

        $this->assertFalse(SiteServiceResource::canAccess());
        $this->assertFalse(SiteServiceResource::canViewAny());
        $this->assertFalse(SiteServiceResource::canCreate());
        $this->assertFalse(SiteServiceResource::canEdit(new SiteService));
        $this->assertFalse(SiteServiceResource::canDelete(new SiteService));
        $this->assertFalse(SiteServiceResource::canView(new SiteService));
        $this->assertFalse(SiteServiceResource::shouldRegisterNavigation());
    }

    public function test_owner_cannot_access_site_service_crud(): void
    {
        $this->actingAs($this->userWithRole(User::ROLE_OWNER));

        $this->assertFalse(SiteServiceResource::canAccess());
        $this->assertFalse(SiteServiceResource::canCreate());
        $this->assertFalse(SiteServiceResource::canEdit(new SiteService));
        $this->assertFalse(SiteServiceResource::canDelete(new SiteService));
        $this->assertFalse(SiteServiceResource::canView(new SiteService));
    }

    private function userWithRole(string $role): User
    {
        $user = new User([
            'role' => $role,
            'status' => User::STATUS_NORMAL,
        ]);
        $user->id = 1;

        return $user;
    }
}
