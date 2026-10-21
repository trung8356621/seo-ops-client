<?php

declare(strict_types=1);

namespace App\Services\ConfigurationSync;

use Omnichannel\Addons\Seo\Services\ConfigurationSync\InstallationMachineCredential;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\InstallationMachineCredentialProvider;

/**
 * Production binding until H1 enrollment stores an installation machine credential.
 * Returns null. It does not create credentials.
 */
final class BlockedInstallationMachineCredentialProvider implements InstallationMachineCredentialProvider
{
    public function current(): ?InstallationMachineCredential
    {
        return null;
    }
}
