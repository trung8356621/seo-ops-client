<?php

declare(strict_types=1);

namespace App\System\Support;

use Illuminate\Http\JsonResponse;

final class SystemApiEnvelope
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    public static function ok(array $data, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'data' => $data,
            'meta' => array_merge([
                'api' => 'system',
                'version' => 'v1',
            ], $meta),
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function error(
        string $code,
        string $message,
        int $status = 400,
        array $details = [],
        ?string $correlationId = null,
    ): JsonResponse {
        return response()->json([
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
                'correlation_id' => $correlationId,
            ],
            'meta' => [
                'api' => 'system',
                'version' => 'v1',
            ],
        ], $status);
    }
}
