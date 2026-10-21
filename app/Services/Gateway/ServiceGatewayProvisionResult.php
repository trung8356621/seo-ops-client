<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Enums\ServiceGatewayProvisionStatus;
use App\Models\ServiceGatewayProvision;

final class ServiceGatewayProvisionResult
{
    public function __construct(
        public readonly ServiceGatewayProvision $provision,
        public readonly bool $credentialCreated,
        public readonly ?string $error = null,
    ) {}

    public function status(): ServiceGatewayProvisionStatus
    {
        return $this->provision->status instanceof ServiceGatewayProvisionStatus
            ? $this->provision->status
            : ServiceGatewayProvisionStatus::from((string) $this->provision->status);
    }
}
