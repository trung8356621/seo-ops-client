<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Policies\ServiceWidgetPolicy;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Header "Dịch vụ" must use the same ServiceWidgetPolicy as the session API —
 * no markup/Vite for non-Owner.
 */
final class ServiceWidgetHeaderAccessTest extends TestCase
{
    private function userWithRole(string $role): User
    {
        $user = new User;
        $user->id = 1;
        $user->status = User::STATUS_NORMAL;
        $user->role = $role;

        return $user;
    }

    /**
     * Mirrors ClientCoreServiceProvider::registerServiceWidgetHeaderHook policy check
     * after isGlobalHelpSurface() is already true (authenticated + service panel).
     */
    private function renderHeaderIfAuthorized(): string
    {
        if (! ServiceWidgetPolicy::allows(Auth::user())) {
            return '';
        }

        return view('filament.hooks.service-widget-header')->render();
    }

    private function setServicePanel(string $panelId): void
    {
        try {
            Filament::setCurrentPanel(Filament::getPanel($panelId));
        } catch (\Throwable $e) {
            self::markTestSkipped('Panel '.$panelId.' unavailable: '.$e->getMessage());
        }
    }

    #[Test]
    public function owner_sees_dich_vu_header_with_lazy_assets(): void
    {
        Auth::login($this->userWithRole(User::ROLE_OWNER));
        $this->setServicePanel('admin');

        self::assertTrue(ServiceWidgetPolicy::allows(Auth::user()));

        $html = $this->renderHeaderIfAuthorized();
        self::assertStringContainsString('data-service-widget-header', $html);
        self::assertStringContainsString('Dịch vụ', $html);
        self::assertStringContainsString('data-service-widget-trigger', $html);
        self::assertStringContainsString('data-ops-service-widget-host', $html);
        self::assertStringContainsString('headerServiceWidget', $html);
        self::assertStringContainsString(route('service-widget.session'), $html);
    }

    #[Test]
    #[DataProvider('nonOwnerRolesProvider')]
    public function non_owner_does_not_render_header_or_assets(string $role): void
    {
        Auth::login($this->userWithRole($role));
        $this->setServicePanel('admin');

        self::assertFalse(ServiceWidgetPolicy::allows(Auth::user()));
        self::assertSame('', $this->renderHeaderIfAuthorized());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonOwnerRolesProvider(): array
    {
        return [
            'staff' => [User::ROLE_STAFF],
            'manager' => [User::ROLE_MANAGER],
            'admin' => [User::ROLE_ADMIN],
        ];
    }

    #[Test]
    public function guest_cannot_access_service_widget_ability(): void
    {
        Auth::logout();
        self::assertFalse(ServiceWidgetPolicy::allows(Auth::user()));
        self::assertSame('', $this->renderHeaderIfAuthorized());
    }

    #[Test]
    public function provider_gates_header_before_view_so_livewire_cannot_surface_button(): void
    {
        $source = (string) file_get_contents(app_path('Core/ClientCoreServiceProvider.php'));

        $abilityPos = strpos($source, 'ServiceWidgetPolicy::allows(auth()->user())');
        $viewPos = strpos($source, "view('filament.hooks.service-widget-header')");

        self::assertNotFalse($abilityPos);
        self::assertNotFalse($viewPos);
        self::assertLessThan($viewPos, $abilityPos, 'Policy must run before Blade/Vite render');

        // Non-Owner: empty hook output — Livewire morph has nothing to reintroduce.
        Auth::login($this->userWithRole(User::ROLE_STAFF));
        $this->setServicePanel('seo');
        self::assertSame('', $this->renderHeaderIfAuthorized());
    }
}
