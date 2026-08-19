<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\Auth\GoogleController;
use App\Services\Auth\GoogleLoginUserService;
use Omnichannel\Addons\SearchFoundation\Filament\Pages\SeoTeam;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CanonicalDisplayNameContractTest extends TestCase
{
    public function test_google_controller_does_not_mass_update_name_on_login(): void
    {
        $controller = (string) file_get_contents(
            (string) (new ReflectionClass(GoogleController::class))->getFileName(),
        );
        $service = (string) file_get_contents(
            (string) (new ReflectionClass(GoogleLoginUserService::class))->getFileName(),
        );

        self::assertStringContainsString('GoogleLoginUserService', $controller);
        self::assertStringNotContainsString('updateOrCreate', $controller);
        self::assertStringNotContainsString("'name' => \$gUser->name", $controller);
        self::assertStringContainsString('Later logins must not overwrite', $service);
        self::assertStringNotContainsString("'name' => \$googleName", $service);
    }

    public function test_team_members_edit_nickname_writes_users_name(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(SeoTeam::class))->getFileName(),
        );

        self::assertStringContainsString("TextColumn::make('name')", $source);
        self::assertStringContainsString("TextInput::make('name')", $source);
        self::assertStringContainsString("'name' => trim((string) (\$data['name'] ?? ''))", $source);
        self::assertStringNotContainsString("setMeta('nickname'", $source);
        self::assertStringNotContainsString("getMeta('nickname'", $source);
        self::assertStringNotContainsString("TextInput::make('nickname')", $source);
    }
}
