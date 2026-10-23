<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\H1\Exceptions\H1TransportException;
use App\Services\H1\Widget\WidgetTokenExchangeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authenticated Admin session → short-lived Service Widget session.
 * Installation identity comes only from enrolled H1 state (never from the browser body).
 */
final class ServiceWidgetSessionController extends Controller
{
    public function __construct(
        private readonly WidgetTokenExchangeClient $exchange,
    ) {}

    public function store(Request $request): JsonResponse
    {
        if (! auth()->check()) {
            return response()->json([
                'error' => [
                    'code' => 'unauthenticated',
                    'message' => 'Bạn cần đăng nhập để mở Dịch vụ.',
                ],
            ], 401);
        }

        // Ignore any client-supplied installation id — server is the authority.
        try {
            $session = $this->exchange->exchange();
        } catch (H1TransportException $e) {
            $status = match ($e->errorCode) {
                'not_enrolled' => 409,
                'server_offline', 'server_unconfigured', 'secret_unconfigured' => 503,
                'scope_mismatch', 'installation_mismatch', 'invalid_scope' => 403,
                'token_expired', 'request_expired', 'invalid_timestamp' => 401,
                'invalid_response' => 502,
                default => 502,
            };

            return response()->json([
                'error' => [
                    'code' => $e->errorCode,
                    'message' => $e->getMessage(),
                ],
            ], $status);
        }

        return response()->json([
            'accessToken' => $session['access_token'],
            'expiresAt' => $session['expires_at'],
            'installationId' => $session['installation_id'],
            'scopes' => $session['scopes'],
            'apiBaseUrl' => $session['api_base_url'],
            'bundleUrl' => $session['bundle_url'],
            'apiTimeoutMs' => $session['api_timeout_ms'],
            'useMock' => false,
        ]);
    }
}
