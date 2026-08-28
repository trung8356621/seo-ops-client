<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\Users\SeoOpsSystemUser;
use App\Services\Users\UserHierarchyService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Source contracts for SEO Ops System user (no RefreshDatabase).
 */
final class SeoOpsSystemUserTest extends TestCase
{
    public function test_system_user_service_is_idempotent_by_stable_identity(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoOpsSystemUser::class))->getFileName(),
        );

        self::assertSame('seo-ops-system@internal.omnichannel', SeoOpsSystemUser::EMAIL);
        self::assertSame('SEO Ops System', SeoOpsSystemUser::NAME);
        self::assertStringContainsString("'is_system' => true", $src);
        self::assertStringContainsString('withTrashed', $src);
        self::assertStringContainsString("where('email', self::EMAIL)", $src);
        self::assertStringContainsString("orWhere('is_system', true)", $src);
        self::assertStringNotContainsString('auth()->id()', $src);
    }

    public function test_policy_hierarchy_and_model_block_system_delete(): void
    {
        $policy = (string) file_get_contents(
            (string) (new ReflectionClass(UserPolicy::class))->getFileName(),
        );
        self::assertStringContainsString('isSystemUser()', $policy);

        $hierarchy = (string) file_get_contents(
            (string) (new ReflectionClass(UserHierarchyService::class))->getFileName(),
        );
        self::assertStringContainsString('isSystemUser()', $hierarchy);
        self::assertStringContainsString('Không thể xóa tài khoản hệ thống', $hierarchy);

        $userModel = (string) file_get_contents(
            (string) (new ReflectionClass(User::class))->getFileName(),
        );
        self::assertStringContainsString('System user cannot be deleted', $userModel);
        self::assertStringContainsString("'is_system'", $userModel)
            || self::assertStringContainsString('is_system', $userModel);

        $resource = (string) file_get_contents(
            (string) (new ReflectionClass(\App\Filament\Resources\UserResource::class))->getFileName(),
        );
        self::assertStringContainsString("where('is_system', false)", $resource);
        self::assertStringContainsString('! $record->isSystemUser()', $resource);
    }

    public function test_split_service_does_not_use_system_user_or_auth_fallback(): void
    {
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR
            .'omnichannel-addons'.DIRECTORY_SEPARATOR
            .'content-projects'.DIRECTORY_SEPARATOR
            .'src'.DIRECTORY_SEPARATOR
            .'Services'.DIRECTORY_SEPARATOR
            .'ContentProject'.DIRECTORY_SEPARATOR
            .'Draft'.DIRECTORY_SEPARATOR
            .'SplitDraftContentProjectService.php';

        if (! is_file($path)) {
            $path = 'D:'.DIRECTORY_SEPARATOR.'work'.DIRECTORY_SEPARATOR
                .'omnichannel-addons'.DIRECTORY_SEPARATOR
                .'content-projects'.DIRECTORY_SEPARATOR
                .'src'.DIRECTORY_SEPARATOR
                .'Services'.DIRECTORY_SEPARATOR
                .'ContentProject'.DIRECTORY_SEPARATOR
                .'Draft'.DIRECTORY_SEPARATOR
                .'SplitDraftContentProjectService.php';
        }

        $src = (string) file_get_contents($path);
        self::assertStringNotContainsString('SeoOpsSystemUser::id()', $src);
        self::assertStringContainsString("'user_id' => \$writerId", $src);
        self::assertStringNotContainsString('auth()->id()', $src);
        self::assertStringNotContainsString("(\$lockedDraft->user_id ?? \$actorId", $src);
    }
}
