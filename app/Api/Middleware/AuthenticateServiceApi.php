<?php

declare(strict_types=1);

namespace App\Api\Middleware;

use App\Api\Auth\ApiCredentialUsageTracker;
use App\Api\Auth\ApiKeyResolver;
use App\Api\Services\ServiceApiContext;
use App\Api\Services\ServiceApiError;
use App\Models\Service;
use App\Models\ServiceApiCredential;
use App\Services\ServiceIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Canonical Service API authentication.
 * Authorization: Bearer <svc_live_…>
 * Uses service_api_credentials only — never the ops-server provisioned Service secret
 * and never legacy per-site service settings keys.
 */
final class AuthenticateServiceApi
{
    public const REQUEST_CONTEXT_KEY = 'service_api_context';

    public function __construct(
        private readonly ApiKeyResolver $resolver,
        private readonly ApiCredentialUsageTracker $usageTracker,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractBearerToken($request);
        if ($token === null) {
            return ServiceApiError::unauthorized();
        }

        $credential = $this->resolver->resolve($token);
        if (! $credential instanceof ServiceApiCredential) {
            return ServiceApiError::unauthorized();
        }

        if ($credential->isRevoked() || $credential->isExpired()) {
            return ServiceApiError::forbidden();
        }

        $credentialService = $credential->service;
        if (! $credentialService instanceof Service) {
            return ServiceApiError::unauthorized();
        }

        if (! $credentialService->is_active) {
            return ServiceApiError::serviceInactive();
        }

        $routeServiceParam = (string) $request->route('service', '');
        if ($routeServiceParam !== '') {
            $routeService = ServiceIdentity::findService($routeServiceParam);
            if (! $routeService instanceof Service) {
                return ServiceApiError::notFound('Service not found.');
            }
            if ((int) $routeService->id !== (int) $credentialService->id) {
                return ServiceApiError::forbidden();
            }
            if (! $routeService->is_active) {
                return ServiceApiError::serviceInactive();
            }
            $credentialService = $routeService;
        }

        $context = new ServiceApiContext($credentialService, $credential);
        $request->attributes->set(self::REQUEST_CONTEXT_KEY, $context);
        app()->instance(ServiceApiContext::class, $context);

        $this->usageTracker->touch($credential);

        return $next($request);
    }

    private function extractBearerToken(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m) !== 1) {
            return null;
        }

        $token = trim((string) $m[1]);

        return $token !== '' ? $token : null;
    }
}
