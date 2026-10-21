<?php

declare(strict_types=1);

namespace App\Services\H1\Exceptions;

final class H1EnrollmentException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}
