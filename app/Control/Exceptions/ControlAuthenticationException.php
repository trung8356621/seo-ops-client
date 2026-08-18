<?php

declare(strict_types=1);

namespace App\Control\Exceptions;

use RuntimeException;

class ControlAuthenticationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 401,
        public readonly string $codeKey = 'unauthorized',
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $codeKey, string $message, int $status = 401): self
    {
        return new self($message, $status, $codeKey);
    }
}
