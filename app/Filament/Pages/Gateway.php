<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Api\Auth\ServiceApiCredentialManager;
use App\Enums\ServiceApiCredentialKind;
use App\Enums\ServiceGatewayProvisionStatus;
use App\Models\Service;
use App\Models\ServiceApiCredential;
use App\Models\ServiceGatewayProvision;
use App\Models\User;
use App\Services\Gateway\EntitlementPresentationStore;
use App\Services\Gateway\ServiceCapabilityManifest;
use App\Services\ServiceIdentity;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * Unified Gateway Admin area:
 * - Control Gate: license & entitled Services (safe presentation only)
 * - API Access Gate: Root-managed user API keys (never managed Service Gate secrets)
 */
final class Gateway extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $slug = 'gateway';

    protected static ?int $navigationSort = 8;

    protected static string $view = 'filament.pages.gateway';

    /** @var 'control'|'api_access' */
    public string $activeTab = 'control';

    public ?string $revealedApiKey = null;

    public ?string $revealedApiKeyName = null;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.system');
    }

    public static function getNavigationLabel(): string
    {
        return __('gateway.nav');
    }

    public function getTitle(): string
    {
        return __('gateway.title');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && in_array((string) $user->role, [User::ROLE_OWNER, User::ROLE_ADMIN], true);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->revealedApiKey = null;
        $this->revealedApiKeyName = null;
    }

    public function canManageApiKeys(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isOwner();
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['control', 'api_access'], true)) {
            return;
        }
        $this->activeTab = $tab;
    }

    /**
     * Safe Control Gate presentation — never secrets / machine credentials / managed keys.
     *
     * @return array{
     *     connected: bool,
     *     license_status: string,
     *     product_name: ?string,
     *     plan_name: ?string,
     *     starts_at: ?string,
     *     ends_at: ?string,
     *     h1_receiver: string,
     *     services: list<array{
     *         slug: string,
     *         name: string,
     *         entitled: bool,
     *         activated: bool,
     *         readiness: string,
     *         readiness_label: string
     *     }>
     * }
     */
    public function controlGateStatus(): array
    {
        $snapshot = app(EntitlementPresentationStore::class)->current();
        $manifest = app(ServiceCapabilityManifest::class);
        $services = [];

        foreach (ServiceIdentity::knownPublicSlugs() as $publicSlug) {
            $row = Schema::hasTable('services') ? ServiceIdentity::findService($publicSlug) : null;
            $entitled = $snapshot->connected && $snapshot->isServiceEntitled($publicSlug);
            $activated = $row instanceof Service && (bool) $row->is_active;

            $readiness = 'unknown';
            if (! $snapshot->connected) {
                $readiness = 'disconnected';
            } elseif (! $entitled) {
                $readiness = 'not_entitled';
            } elseif (Schema::hasTable('service_gateway_provisions') && $row instanceof Service) {
                $provision = ServiceGatewayProvision::query()->where('service_id', $row->id)->first();
                if ($provision?->status instanceof ServiceGatewayProvisionStatus) {
                    $readiness = $provision->status->value;
                } elseif (is_string($provision?->status)) {
                    $readiness = $provision->status;
                } else {
                    $readiness = $manifest->requiresManagedCredential($publicSlug) ? 'pending' : 'not_required';
                }
            }

            $services[] = [
                'slug' => $publicSlug,
                'name' => ServiceIdentity::displayName($publicSlug),
                'entitled' => $entitled,
                'activated' => $activated,
                'readiness' => $readiness,
                'readiness_label' => __('gateway.readiness_'.$readiness),
            ];
        }

        return [
            'connected' => $snapshot->connected,
            'license_status' => $snapshot->connected ? $snapshot->licenseStatus : 'disconnected',
            'product_name' => $snapshot->connected ? $snapshot->productName : null,
            'plan_name' => $snapshot->connected ? $snapshot->planName : null,
            'starts_at' => $snapshot->connected && $snapshot->startsAt
                ? $snapshot->startsAt->toDateString()
                : null,
            'ends_at' => $snapshot->connected && $snapshot->endsAt
                ? $snapshot->endsAt->toDateString()
                : null,
            'h1_receiver' => 'pending_integration',
            'services' => $services,
        ];
    }

    /**
     * User API Access keys only — never Service Gate managed credentials.
     *
     * @return Collection<int, ServiceApiCredential>
     */
    public function apiCredentials(): Collection
    {
        if (! Schema::hasTable('service_api_credentials')) {
            return collect();
        }

        $query = ServiceApiCredential::query()->with('service')->orderByDesc('id');
        if (Schema::hasColumn('service_api_credentials', 'kind')) {
            $query->where(function ($q): void {
                $q->where('kind', ServiceApiCredentialKind::User->value)
                    ->orWhereNull('kind');
            });
        }

        return $query->get();
    }

    public function activeKeyCount(): int
    {
        return $this->apiCredentials()->filter(
            static fn (ServiceApiCredential $c): bool => $c->statusLabel() === 'active'
        )->count();
    }

    public function totalKeyCount(): int
    {
        return $this->apiCredentials()->count();
    }

    public function dismissRevealedApiKey(): void
    {
        $this->revealedApiKey = null;
        $this->revealedApiKeyName = null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createApiKey')
                ->label(__('gateway.api_access_create'))
                ->color('primary')
                ->visible(fn (): bool => $this->canManageApiKeys() && $this->activeTab === 'api_access')
                ->form([
                    Select::make('service')
                        ->label(__('gateway.api_access_service'))
                        ->options(fn (): array => $this->serviceOptions())
                        ->required(),
                    TextInput::make('name')
                        ->label(__('site-service.api_access_name'))
                        ->required()
                        ->maxLength(120)
                        ->default('Integration key'),
                    TagsInput::make('scopes')
                        ->label(__('site-service.api_access_scopes'))
                        ->placeholder('service:read')
                        ->helperText(__('gateway.api_access_scopes_helper'))
                        ->suggestions(['service:read', 'seo:read', 'content-projects:draft:write'])
                        ->default(['service:read']),
                    DateTimePicker::make('expires_at')
                        ->label(__('site-service.api_access_expires'))
                        ->helperText(__('site-service.api_access_expires_helper'))
                        ->native(false)
                        ->seconds(false),
                ])
                ->action(function (array $data): void {
                    $this->createApiCredential($data);
                }),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function serviceOptions(): array
    {
        $options = [];
        foreach (ServiceIdentity::knownPublicSlugs() as $slug) {
            if (ServiceIdentity::findService($slug) instanceof Service) {
                $options[$slug] = ServiceIdentity::displayName($slug);
            }
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createApiCredential(array $data): void
    {
        if (! $this->canManageApiKeys()) {
            Notification::make()->title(__('gateway.api_access_root_only'))->danger()->send();

            return;
        }

        $service = ServiceIdentity::findService((string) ($data['service'] ?? ''));
        if (! $service instanceof Service) {
            Notification::make()->title(__('site-service.service_not_provisioned'))->danger()->send();

            return;
        }

        try {
            $expiresAt = filled($data['expires_at'] ?? null)
                ? Carbon::parse((string) $data['expires_at'])
                : null;
            $result = app(ServiceApiCredentialManager::class)->create(
                $service,
                (string) ($data['name'] ?? ''),
                $data['scopes'] ?? [],
                $expiresAt,
                Auth::id() !== null ? (int) Auth::id() : null,
            );
        } catch (InvalidArgumentException $e) {
            Notification::make()->title(__('site-service.api_access_create_failed'))->body($e->getMessage())->danger()->send();

            return;
        } catch (Throwable $e) {
            Notification::make()->title(__('site-service.api_access_create_failed'))->body($e->getMessage())->danger()->send();

            return;
        }

        $this->revealedApiKey = $result->rawKey;
        $this->revealedApiKeyName = $result->credential->name;
        Notification::make()->title(__('site-service.api_access_created_notice'))->success()->send();
    }

    public function revokeApiCredential(int $credentialId): void
    {
        if (! $this->canManageApiKeys()) {
            Notification::make()->title(__('gateway.api_access_root_only'))->danger()->send();

            return;
        }

        $credential = $this->findUserCredential($credentialId);
        if (! $credential instanceof ServiceApiCredential) {
            return;
        }

        app(ServiceApiCredentialManager::class)->revoke($credential);
        $this->dismissRevealedApiKey();
        Notification::make()->title(__('site-service.api_access_revoked'))->success()->send();
    }

    public function rotateApiCredential(int $credentialId): void
    {
        if (! $this->canManageApiKeys()) {
            Notification::make()->title(__('gateway.api_access_root_only'))->danger()->send();

            return;
        }

        $credential = $this->findUserCredential($credentialId);
        if (! $credential instanceof ServiceApiCredential) {
            return;
        }

        try {
            $result = app(ServiceApiCredentialManager::class)->rotate(
                $credential,
                createdBy: Auth::id() !== null ? (int) Auth::id() : null,
            );
        } catch (Throwable $e) {
            Notification::make()->title(__('site-service.api_access_rotate_failed'))->body($e->getMessage())->danger()->send();

            return;
        }

        $this->revealedApiKey = $result->rawKey;
        $this->revealedApiKeyName = $result->credential->name;
        Notification::make()->title(__('site-service.api_access_rotated'))->success()->send();
    }

    private function findUserCredential(int $credentialId): ?ServiceApiCredential
    {
        $query = ServiceApiCredential::query()->whereKey($credentialId);
        if (Schema::hasColumn('service_api_credentials', 'kind')) {
            $query->where(function ($q): void {
                $q->where('kind', ServiceApiCredentialKind::User->value)
                    ->orWhereNull('kind');
            });
        }

        $credential = $query->first();
        if (! $credential instanceof ServiceApiCredential) {
            Notification::make()->title(__('site-service.api_access_not_found'))->danger()->send();

            return null;
        }

        return $credential;
    }
}
