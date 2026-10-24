<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Policies\SupportWidgetPolicy;
use App\Services\H1\Exceptions\H1TransportException;
use App\Services\H1\Widget\WidgetTokenExchangeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authenticated session → short-lived OPS Support Widget bearer (H1 machine exchange).
 * No local Ticket persistence. Installation id comes only from enrolled H1 state.
 */
final class SupportWidgetSessionController extends Controller
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
                    'message' => 'Bạn cần đăng nhập để gửi hỗ trợ.',
                ],
            ], 401);
        }

        if (! SupportWidgetPolicy::allows(auth()->user())) {
            return response()->json([
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'Không có quyền mở Support Ticket.',
                ],
            ], 403);
        }

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

        if (! in_array('widget:support', $session['scopes'], true)) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_scope',
                    'message' => 'OPS-SERVER chưa cấp scope widget:support.',
                ],
            ], 403);
        }

        $user = auth()->user();
        $bundleUrl = (string) config(
            'h1-license.support.bundle_url',
            '/vendor/ops-support-widget/ops-support-widget.js'
        );
        if (! str_starts_with($bundleUrl, 'http://') && ! str_starts_with($bundleUrl, 'https://') && ! str_starts_with($bundleUrl, '/')) {
            $bundleUrl = '/'.$bundleUrl;
        }
        if (str_starts_with($bundleUrl, '/')) {
            $bundleUrl = url($bundleUrl);
        }

        return response()->json([
            'accessToken' => $session['access_token'],
            'expiresAt' => $session['expires_at'],
            'installationId' => $session['installation_id'],
            'scopes' => $session['scopes'],
            'apiBaseUrl' => $session['api_base_url'],
            'bundleUrl' => $bundleUrl,
            'apiTimeoutMs' => (int) config('h1-license.support.api_timeout_ms', 8000),
            'senderName' => (string) ($user?->name ?? $user?->email ?? 'User'),
            'senderEmail' => (string) ($user?->email ?? ''),
            'pageUrl' => url()->previous() ?: url()->current(),
        ]);
    }
}
