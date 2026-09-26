<?php

declare(strict_types=1);

namespace App\Api\Services;

use Illuminate\Http\JsonResponse;

/**
 * Canonical JSON error envelope for Service API routes.
 */
final class ServiceApiError
{
    public const UNAUTHORIZED = 'service_api_unauthorized';

    public const FORBIDDEN = 'service_api_forbidden';

    public const SERVICE_INACTIVE = 'service_api_service_inactive';

    public const SCOPE_DENIED = 'service_api_scope_denied';

    public const NOT_FOUND = 'service_api_not_found';

    public static function json(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }

    public static function unauthorized(string $message = 'Unauthorized.'): JsonResponse
    {
        return self::json(self::UNAUTHORIZED, $message, 401);
    }

    public static function forbidden(string $message = 'Forbidden.'): JsonResponse
    {
        return self::json(self::FORBIDDEN, $message, 403);
    }

    public static function serviceInactive(): JsonResponse
    {
        return self::json(self::SERVICE_INACTIVE, 'Service is inactive.', 403);
    }

    public static function scopeDenied(): JsonResponse
    {
        return self::json(self::SCOPE_DENIED, 'Insufficient scope.', 403);
    }

    public static function notFound(string $message = 'Not found.'): JsonResponse
    {
        return self::json(self::NOT_FOUND, $message, 404);
    }
}
