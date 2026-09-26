<?php

declare(strict_types=1);

namespace App\Api\Middleware;

use App\Api\Access\TemporaryServiceAccessContext;
use App\Api\Access\TemporaryServiceAccessManager;
use App\Api\Access\TemporaryServiceAccessResolver;
use App\Api\Services\ServiceApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate temporary Service Access URLs (no permanent Bearer key).
 */
final class ResolveTemporaryServiceAccess
{
    public const REQUEST_CONTEXT_KEY = 'temporary_service_access_context';

    public function __construct(
        private readonly TemporaryServiceAccessResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->route('token', '');
        $context = $this->resolver->resolve($token);
        if (! $context instanceof TemporaryServiceAccessContext) {
            return ServiceApiError::json(
                ServiceApiError::TEMPORARY_ACCESS_INVALID,
                'Temporary access is invalid or expired.',
                401,
            );
        }

        if (! $context->hasScope(TemporaryServiceAccessManager::READ_SCOPE)) {
            return ServiceApiError::forbidden();
        }

        $request->attributes->set(self::REQUEST_CONTEXT_KEY, $context);
        app()->instance(TemporaryServiceAccessContext::class, $context);

        $response = $next($request);
        if (method_exists($response, 'headers')) {
            $response->headers->set('Cache-Control', 'no-store');
        }

        return $response;
    }
}
