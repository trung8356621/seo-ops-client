<?php

declare(strict_types=1);

namespace App\Services\H1\Enrollment;

use App\Services\H1\Exceptions\H1EnrollmentException;

/**
 * Test/dev transport — never invents a Server HTTP protocol.
 */
final class FixtureEnrollmentTransport implements EnrollmentTransport
{
    /** @var array<string, array<string, mixed>> */
    private array $tokens = [];

    /** @var array<string, true> */
    private array $consumed = [];

    /**
     * @param  array<string, mixed>  $response
     */
    public function registerToken(string $token, array $response, bool $expired = false): void
    {
        $this->tokens[$token] = [
            'response' => $response,
            'expired' => $expired,
        ];
    }

    public function consume(string $enrollmentToken, string $clientVersion): array
    {
        unset($clientVersion);

        if (! isset($this->tokens[$enrollmentToken])) {
            throw new H1EnrollmentException('invalid_receipt', 'Enrollment receipt token not found.');
        }

        $entry = $this->tokens[$enrollmentToken];
        if ($entry['expired'] === true) {
            throw new H1EnrollmentException('receipt_expired', 'Enrollment receipt has expired.');
        }

        /** @var array<string, mixed> $response */
        $response = $entry['response'];
        $isRetry = isset($this->consumed[$enrollmentToken]);
        $this->consumed[$enrollmentToken] = true;

        $response['success'] = true;
        $response['is_retry'] = $isRetry;
        $response['code'] = $isRetry ? 'enrolled_retry' : 'enrolled';

        return $response;
    }
}
