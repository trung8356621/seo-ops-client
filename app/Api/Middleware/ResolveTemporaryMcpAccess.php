<?php

declare(strict_types=1);

namespace App\Api\Middleware;

use App\Api\Mcp\TemporaryMcpAccessContext;
use App\Api\Mcp\TemporaryMcpAccessResolver;
use App\Api\Services\ServiceApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate temporary MCP capability URLs (no permanent Bearer key).
 */
final class ResolveTemporaryMcpAccess
{
    public const REQUEST_CONTEXT_KEY = 'temporary_mcp_access_context';

    public function __construct(
        private readonly TemporaryMcpAccessResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->route('token', '');
        $context = $this->resolver->resolve($token);
        if (! $context instanceof TemporaryMcpAccessContext) {
            return ServiceApiError::json(
                ServiceApiError::TEMPORARY_ACCESS_INVALID,
                'Temporary MCP access is invalid or expired.',
                401,
            );
        }

        if (! $context->hasScope('mcp:read')) {
            return ServiceApiError::forbidden();
        }

        $request->attributes->set(self::REQUEST_CONTEXT_KEY, $context);
        app()->instance(TemporaryMcpAccessContext::class, $context);

        $response = $next($request);
        if (method_exists($response, 'headers')) {
            $response->headers->set('Cache-Control', 'no-store');
        }

        return $response;
    }
}
