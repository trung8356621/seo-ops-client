<?php

declare(strict_types=1);

namespace App\Api\Middleware;

use App\Api\Services\ServiceApiContext;
use App\Api\Services\ServiceApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require one or more scopes on the authenticated Service API credential.
 * Route usage: service.api.scope:service:read
 */
final class RequireServiceApiScope
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $context = $request->attributes->get(AuthenticateServiceApi::REQUEST_CONTEXT_KEY);
        if (! $context instanceof ServiceApiContext) {
            $context = app()->bound(ServiceApiContext::class)
                ? app(ServiceApiContext::class)
                : null;
        }

        if (! $context instanceof ServiceApiContext) {
            return ServiceApiError::unauthorized();
        }

        foreach ($scopes as $scope) {
            $scope = trim($scope);
            if ($scope === '') {
                continue;
            }
            if (! $context->hasScope($scope)) {
                return ServiceApiError::scopeDenied();
            }
        }

        return $next($request);
    }
}
