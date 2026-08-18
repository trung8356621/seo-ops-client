<?php

declare(strict_types=1);

namespace App\Control;

use App\Control\Commands\Handlers\ServicesApplyHandler;
use App\Enums\ClientControlStatus;
use App\Models\ClientControlState;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Client-initiated enrollment only. Never retried, never scheduled.
 * Raw API key is used for a single HTTP call and is not persisted.
 */
final class ClientEnrollmentService
{
    public function __construct(
        private readonly ServicesApplyHandler $servicesApplyHandler,
    ) {}

    public function enroll(string $controlServerUrl, string $apiKey): EnrollmentResult
    {
        $url = $this->normalizeControlServerUrl($controlServerUrl);
        if ($url === null) {
            return EnrollmentResult::failed(__('client_control.enroll_invalid_url'));
        }

        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return EnrollmentResult::failed(__('client_control.enroll_missing_api_key'));
        }

        if (! Schema::hasTable('client_control_state')) {
            return EnrollmentResult::failed(__('client_control.enroll_not_ready'));
        }

        $existing = ClientControlState::query()->orderBy('id')->first();
        if (
            $existing instanceof ClientControlState
            && in_array($existing->status, [ClientControlStatus::Active, ClientControlStatus::Locked], true)
        ) {
            return EnrollmentResult::failed(__('client_control.enroll_already_connected'));
        }

        $endpoint = $url.$this->enrollPath();
        $payload = [
            'client_version' => (string) config('client_control.client_version'),
            'app_url' => (string) config('app.url'),
            'hostname' => gethostname() ?: null,
        ];

        try {
            $response = Http::timeout((int) config('client_control.http_timeout_seconds', 10))
                ->connectTimeout((int) config('client_control.http_connect_timeout_seconds', 5))
                ->acceptJson()
                ->asJson()
                ->withToken($apiKey)
                ->post($endpoint, $payload);
        } catch (ConnectionException) {
            return EnrollmentResult::failed(__('client_control.enroll_server_unavailable'));
        } catch (Throwable) {
            return EnrollmentResult::failed(__('client_control.enroll_server_unavailable'));
        }

        if ($response->failed()) {
            return EnrollmentResult::failed(__('client_control.enroll_server_unavailable'));
        }

        try {
            $body = $response->json();
        } catch (RequestException) {
            return EnrollmentResult::failed(__('client_control.enroll_invalid_response'));
        }

        if (! is_array($body)) {
            return EnrollmentResult::failed(__('client_control.enroll_invalid_response'));
        }

        return $this->persistEnrollment($existing, $url, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function persistEnrollment(?ClientControlState $state, string $url, array $body): EnrollmentResult
    {
        $installationId = trim((string) ($body['installation_id'] ?? ''));
        $secret = trim((string) ($body['installation_secret'] ?? ''));
        $statusRaw = strtolower(trim((string) ($body['status'] ?? 'active')));

        if ($installationId === '' || $secret === '') {
            return EnrollmentResult::failed(__('client_control.enroll_invalid_response'));
        }

        $status = ClientControlStatus::tryFrom($statusRaw);
        if ($status === null || $status === ClientControlStatus::Unregistered) {
            return EnrollmentResult::failed(__('client_control.enroll_invalid_response'));
        }

        try {
            DB::transaction(function () use ($state, $url, $installationId, $secret, $status, $body): void {
                $row = $state ?? ClientControlState::current();
                $row->refresh();
                $row->fill([
                    'installation_id' => $installationId,
                    'control_server_url' => $url,
                    'installation_secret' => $secret,
                    'status' => $status,
                    'client_version' => (string) config('client_control.client_version'),
                    'connected_at' => now(),
                    'locked_at' => $status === ClientControlStatus::Locked ? now() : null,
                ]);
                $row->save();

                $snapshot = $body['active_services'] ?? null;
                $revision = $body['services_revision'] ?? $body['revision'] ?? null;
                if (is_array($snapshot)) {
                    $this->servicesApplyHandler->handle([
                        'revision' => is_numeric($revision) ? (int) $revision : 0,
                        'mode' => 'replace',
                        'active_services' => $snapshot,
                    ]);
                } elseif (is_numeric($revision)) {
                    $row->services_revision = (int) $revision;
                    $row->save();
                }
            });
        } catch (Throwable) {
            return EnrollmentResult::failed(__('client_control.enroll_persist_failed'));
        }

        return EnrollmentResult::succeeded($installationId, __('client_control.enroll_success'));
    }

    private function normalizeControlServerUrl(string $url): ?string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '') {
            return null;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }

    private function enrollPath(): string
    {
        $path = (string) config('client_control.enroll_path', '/api/control/v1/enrollments');
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return $path;
    }
}
