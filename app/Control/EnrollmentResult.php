<?php

declare(strict_types=1);

namespace App\Control;

final readonly class EnrollmentResult
{
    public function __construct(
        public bool $ok,
        public string $message,
        public ?string $installationId = null,
    ) {}

    public static function failed(string $message): self
    {
        return new self(ok: false, message: $message);
    }

    public static function succeeded(string $installationId, string $message): self
    {
        return new self(ok: true, message: $message, installationId: $installationId);
    }
}
