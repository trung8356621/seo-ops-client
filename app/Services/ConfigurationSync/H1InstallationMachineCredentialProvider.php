<?php

declare(strict_types=1);

namespace App\Services\ConfigurationSync;

use App\Services\H1\Persistence\InstallationStateRepository;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\InstallationMachineCredential;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\InstallationMachineCredentialProvider;

/**
 * Supplies the enrolled H1 machine credential to seo-ops-config/1.
 * Returns null when not enrolled or credential unavailable — never fabricates secrets.
 */
final class H1InstallationMachineCredentialProvider implements InstallationMachineCredentialProvider
{
    public function __construct(
        private readonly InstallationStateRepository $installations,
    ) {}

    public function current(): ?InstallationMachineCredential
    {
        return $this->installations->machineCredential();
    }
}
