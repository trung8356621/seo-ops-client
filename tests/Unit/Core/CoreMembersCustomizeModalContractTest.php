<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Members\MembersSectionRegistry;
use App\Filament\Resources\UserResource;
use App\Models\User;
use Omnichannel\Addons\SearchFoundation\Members\SeoMembersSectionContributor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CoreMembersCustomizeModalContractTest extends TestCase
{
    public function test_user_resource_has_customize_modal_action_with_display_name(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(UserResource::class))->getFileName());

        self::assertStringContainsString("Action::make('customizeMember')", $source);
        self::assertStringContainsString('Tùy chỉnh', $source);
        self::assertStringContainsString("TextInput::make('name')", $source);
        self::assertStringContainsString('MembersSectionRegistry', $source);
        self::assertStringContainsString('customizeModalSchema', $source);
        self::assertStringContainsString('fillCustomizeModal', $source);
        self::assertStringContainsString('afterUserSaved', $source);
        self::assertStringNotContainsString("make('nickname')", $source);
    }

    public function test_seo_contributor_registered_via_seo_service_provider(): void
    {
        $provider = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Seo\SeoServiceProvider::class))->getFileName()
        );
        self::assertStringContainsString('SeoMembersSectionContributor', $provider);
        self::assertStringContainsString('MembersSectionRegistry', $provider);
    }

    public function test_seo_enabled_customize_schema_includes_capacity_fields(): void
    {
        $contributor = new SeoMembersSectionContributor();
        self::assertTrue($contributor->isAvailable());

        $source = (string) file_get_contents((new ReflectionClass($contributor))->getFileName());
        self::assertStringContainsString('seo_capacity_use_default', $source);
        self::assertStringContainsString('seo_monthly_capacity_override', $source);
        self::assertStringContainsString('Giới hạn bài SEO / tháng', $source);
        self::assertStringContainsString('Dùng mặc định', $source);
        self::assertStringContainsString('ContentProjectWriterCapacitySettingsService', $source);

        $registry = new MembersSectionRegistry();
        $registry->register($contributor);
        // Avoid mounting Filament components in pure PHPUnit — source + registration is enough.
        self::assertTrue($registry->has('seo-members'));
    }

    public function test_registry_customize_schema_empty_when_no_contributors(): void
    {
        $registry = new MembersSectionRegistry();
        self::assertSame([], $registry->customizeModalSchema());
        self::assertSame([], $registry->fillCustomizeModal(new User()));
    }
}
