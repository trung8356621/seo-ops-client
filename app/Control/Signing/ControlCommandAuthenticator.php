<?php

declare(strict_types=1);

namespace App\Control\Signing;

use App\Control\Commands\ControlCommandEnvelope;
use App\Control\Exceptions\ControlAuthenticationException;
use App\Enums\ClientControlStatus;
use App\Models\ClientControlState;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

final class ControlCommandAuthenticator
{
    public function __construct(
        private readonly ControlRequestSigner $signer,
    ) {}

    public function authenticate(Request $request): ControlCommandEnvelope
    {
        if (! Schema::hasTable('client_control_state')) {
            throw ControlAuthenticationException::invalid('unregistered', 'Unknown installation.', 401);
        }

        $body = $request->json()->all();
        if (! is_array($body)) {
            throw ControlAuthenticationException::invalid('malformed', 'Malformed command.', 400);
        }

        $installationId = trim((string) ($body['installation_id'] ?? ''));
        $commandId = trim((string) ($body['command_id'] ?? ''));
        $issuedAt = trim((string) ($body['issued_at'] ?? ''));
        $command = trim((string) ($body['command'] ?? ''));
        $payload = $body['payload'] ?? [];

        if ($installationId === '' || $commandId === '' || $issuedAt === '' || $command === '') {
            throw ControlAuthenticationException::invalid('malformed', 'Malformed command.', 400);
        }

        if (! $this->isUuid($commandId) || ! $this->isUuid($installationId)) {
            throw ControlAuthenticationException::invalid('malformed', 'Malformed command.', 400);
        }

        if (! is_array($payload)) {
            throw ControlAuthenticationException::invalid('malformed', 'Malformed command.', 400);
        }

        $state = ClientControlState::query()->orderBy('id')->first();
        if (! $state instanceof ClientControlState) {
            throw ControlAuthenticationException::invalid('unregistered', 'Unknown installation.', 401);
        }

        if ($state->status === ClientControlStatus::Unregistered || $state->installation_id === null) {
            throw ControlAuthenticationException::invalid('unregistered', 'Unknown installation.', 401);
        }

        if ($state->status === ClientControlStatus::Revoked) {
            throw ControlAuthenticationException::invalid('revoked', 'Installation revoked.', 403);
        }

        if (! hash_equals((string) $state->installation_id, $installationId)) {
            throw ControlAuthenticationException::invalid('wrong_installation', 'Wrong installation.', 403);
        }

        $secret = (string) $state->installation_secret;
        if ($secret === '') {
            throw ControlAuthenticationException::invalid('unregistered', 'Unknown installation.', 401);
        }

        $this->assertIssuedAtFresh($issuedAt);

        $headerName = (string) config('client_control.signature_header', 'X-Omi-Control-Signature');
        $provided = (string) $request->headers->get($headerName, '');
        $expected = $this->signer->sign(
            $secret,
            $installationId,
            $commandId,
            $issuedAt,
            $command,
            $payload,
        );

        if ($provided === '' || ! $this->signer->matches($secret, $expected, $provided)) {
            throw ControlAuthenticationException::invalid('invalid_signature', 'Invalid signature.', 401);
        }

        return new ControlCommandEnvelope(
            installationId: $installationId,
            commandId: $commandId,
            issuedAt: $issuedAt,
            command: $command,
            payload: $payload,
            signature: $provided,
        );
    }

    private function assertIssuedAtFresh(string $issuedAt): void
    {
        try {
            $issued = CarbonImmutable::parse($issuedAt)->utc();
        } catch (\Throwable) {
            throw ControlAuthenticationException::invalid('stale', 'Stale request.', 401);
        }

        $now = CarbonImmutable::now('UTC');
        $age = $now->getTimestamp() - $issued->getTimestamp();
        $maxAge = (int) config('client_control.command_max_age_seconds', 300);
        $futureSkew = (int) config('client_control.command_future_skew_seconds', 30);

        if ($age > $maxAge || $age < -$futureSkew) {
            throw ControlAuthenticationException::invalid('stale', 'Stale request.', 401);
        }
    }

    private function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
            $value,
        );
    }
}
