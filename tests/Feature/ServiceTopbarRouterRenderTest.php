<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Workspace\ServiceTopbarRouter;
use App\Core\Workspace\WorkspaceDestination;
use App\Core\Workspace\WorkspaceDestinationRegistry;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Render shared service router HTML for Admin / SEO / Seeding panel contexts.
 */
final class ServiceTopbarRouterRenderTest extends TestCase
{
    public function test_rendered_hook_html_for_each_service_path(): void
    {
        $urls = [
            'admin' => url('/admin'),
            'seo' => url('/seo'),
            'seeding' => url('/seeding'),
        ];

        $registry = new WorkspaceDestinationRegistry;
        foreach ([
            ['admin', $urls['admin'], 0],
            ['seo', $urls['seo'], 10],
            ['seeding', $urls['seeding'], 20],
        ] as [$key, $url, $sort]) {
            $registry->register(new WorkspaceDestination(
                key: $key,
                label: $key,
                url: $url,
                sort: $sort,
                panelId: $key === 'seo' ? 'seo' : $key,
                canAccess: static fn (User $user): bool => true,
            ));
        }

        $this->app->instance(WorkspaceDestinationRegistry::class, $registry);
        $this->app->instance(ServiceTopbarRouter::class, new ServiceTopbarRouter($registry));

        $user = new User;
        $user->status = User::STATUS_NORMAL;
        $user->role = User::ROLE_OWNER;
        Auth::login($user);

        foreach ([
            ['panel' => 'admin', 'path' => '/admin', 'active' => 'admin'],
            ['panel' => 'seo-main', 'path' => '/seo', 'active' => 'seo'],
            ['panel' => 'seeding', 'path' => '/seeding', 'active' => 'seeding'],
        ] as $ctx) {
            try {
                Filament::setCurrentPanel(Filament::getPanel($ctx['panel']));
            } catch (\Throwable $e) {
                self::markTestSkipped('Panel '.$ctx['panel'].' unavailable: '.$e->getMessage());
            }

            $this->app->instance('request', \Illuminate\Http\Request::create($ctx['path'], 'GET'));

            $html = view('filament.hooks.service-topbar-router')->render();

            self::assertStringContainsString('data-service-router', $html, $ctx['panel']);
            self::assertStringContainsString('data-service="admin"', $html);
            self::assertStringContainsString('data-service="seo"', $html);
            self::assertStringContainsString('data-service="seeding"', $html);
            foreach (['admin', 'seo', 'seeding'] as $key) {
                if ($key === $ctx['active']) {
                    continue;
                }
                self::assertStringContainsString('href="'.$urls[$key].'"', $html);
            }
            self::assertMatchesRegularExpression(
                '/<span[^>]*data-service="'.preg_quote($ctx['active'], '/').'"[^>]*data-active="1"/',
                $html,
            );
            self::assertDoesNotMatchRegularExpression(
                '/<a[^>]*data-service="'.preg_quote($ctx['active'], '/').'"/',
                $html,
            );
        }
    }

    public function test_inaccessible_admin_omitted_from_rendered_html(): void
    {
        $registry = new WorkspaceDestinationRegistry;
        $registry->register(new WorkspaceDestination(
            key: 'admin',
            label: 'Admin',
            url: url('/admin'),
            sort: 0,
            panelId: 'admin',
            canAccess: static fn (User $user): bool => false,
        ));
        $registry->register(new WorkspaceDestination(
            key: 'seeding',
            label: 'Seeding',
            url: url('/seeding'),
            sort: 20,
            panelId: 'seeding',
            canAccess: static fn (User $user): bool => true,
        ));

        $this->app->instance(WorkspaceDestinationRegistry::class, $registry);
        $this->app->instance(ServiceTopbarRouter::class, new ServiceTopbarRouter($registry));

        $user = new User;
        $user->status = User::STATUS_NORMAL;
        $user->role = User::ROLE_STAFF;
        Auth::login($user);

        try {
            Filament::setCurrentPanel(Filament::getPanel('seeding'));
        } catch (\Throwable $e) {
            self::markTestSkipped('Seeding panel unavailable: '.$e->getMessage());
        }

        $this->app->instance('request', \Illuminate\Http\Request::create('/seeding', 'GET'));
        $html = view('filament.hooks.service-topbar-router')->render();

        self::assertStringContainsString('data-service="seeding"', $html);
        self::assertStringNotContainsString('data-service="admin"', $html);
        self::assertStringNotContainsString(url('/admin'), $html);
    }
}
