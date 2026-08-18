<?php

declare(strict_types=1);

namespace App\Control\Signing;

final class ControlRequestSigner
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function sign(
        string $secret,
        string $installationId,
        string $commandId,
        string $issuedAt,
        string $command,
        array $payload,
    ): string {
        $canonical = $this->canonicalize(
            $installationId,
            $commandId,
            $issuedAt,
            $command,
            $payload,
        );

        return hash_hmac('sha256', $canonical, $secret);
    }

    public function matches(string $secret, string $expectedHex, string $provided): bool
    {
        $providedHex = $this->normalizeProvidedSignature($provided);
        if ($providedHex === '' || ! ctype_xdigit($providedHex) || strlen($providedHex) !== 64) {
            return false;
        }

        return hash_equals(strtolower($expectedHex), strtolower($providedHex));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function canonicalize(
        string $installationId,
        string $commandId,
        string $issuedAt,
        string $command,
        array $payload,
    ): string {
        $envelope = [
            'command' => $command,
            'command_id' => $commandId,
            'installation_id' => $installationId,
            'issued_at' => $issuedAt,
            'payload' => $this->sortRecursive($payload),
        ];

        return (string) json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function payloadHash(array $payload): string
    {
        $canonical = (string) json_encode($this->sortRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $canonical);
    }

    /**
     * @param  array<string|int, mixed>  $value
     * @return array<string|int, mixed>
     */
    private function sortRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }

        if ($this->isList($value)) {
            return $value;
        }

        ksort($value, SORT_STRING);

        return $value;
    }

    /**
     * @param  array<string|int, mixed>  $value
     */
    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_is_list($value);
    }

    private function normalizeProvidedSignature(string $provided): string
    {
        $provided = trim($provided);
        if (str_starts_with(strtolower($provided), 'sha256=')) {
            $provided = substr($provided, 7);
        }

        return $provided;
    }
}
