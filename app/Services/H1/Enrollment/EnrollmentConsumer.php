<?php

declare(strict_types=1);

namespace App\Services\H1\Enrollment;

use App\Services\Gateway\EntitlementAppliedHook;
use App\Services\Gateway\VerifiedEntitlementSnapshot;
use App\Services\H1\Exceptions\H1EnrollmentException;
use App\Services\H1\Persistence\InstallationStateRepository;
use App\Services\H1\Snapshot\EntitlementSnapshotMapper;
use Illuminate\Support\Facades\Schema;

final class EnrollmentConsumer
{
    public function __construct(
        private readonly EnrollmentTransport $transport,
        private readonly InstallationStateRepository $installations,
        private readonly EntitlementSnapshotMapper $mapper,
        private readonly EntitlementAppliedHook $appliedHook,
    ) {}

    /**
     * @return array{installation_id: string, credential_id: string, is_retry: bool, revision: int}
     */
    public function enroll(string $enrollmentToken, ?string $clientVersion = null): array
    {
        $token = trim($enrollmentToken);
        if ($token === '' || ! preg_match('/^tok_[A-Za-z0-9_-]{8,124}$/', $token)) {
            throw new H1EnrollmentException('invalid_receipt', 'Enrollment token format is invalid.');
        }

        if (! Schema::hasTable('h1_installations')) {
            throw new H1EnrollmentException('schema_missing', 'H1 license tables are not migrated.');
        }

        $existing = $this->installations->currentInstallation();
        $version = $clientVersion ?: (string) config('app.version', config('configuration-sync.client_version', '0.0.0'));

        $remote = $this->transport->consume($token, $version);
        if (! ($remote['success'] ?? false)) {
            throw new H1EnrollmentException(
                (string) ($remote['code'] ?? 'enrollment_failed'),
                (string) ($remote['detail'] ?? 'Enrollment failed.'),
                false,
            );
        }

        $installationId = (string) ($remote['installation_id'] ?? '');
        $credential = $remote['credential'] ?? null;
        if ($installationId === '' || ! is_array($credential)) {
            throw new H1EnrollmentException('invalid_response', 'Enrollment response missing installation/credential.');
        }

        $credentialId = (string) ($credential['id'] ?? '');
        $credentialVersion = (int) ($credential['version'] ?? 0);
        $secret = (string) ($credential['secret'] ?? '');
        if ($credentialId === '' || $credentialVersion < 1 || $secret === '') {
            throw new H1EnrollmentException('invalid_response', 'Enrollment credential payload is incomplete.');
        }

        if ($existing !== null && $existing->installation_id !== $installationId) {
            throw new H1EnrollmentException(
                'already_enrolled',
                'Refusing to replace an existing enrolled Installation identity.',
            );
        }

        $entitlement = is_array($remote['entitlement'] ?? null) ? $remote['entitlement'] : [];
        $revision = (int) ($remote['entitlement_revision'] ?? ($entitlement['revision'] ?? 1));
        $snapshot = $this->mapper->normalizeEntitlement($entitlement, $installationId, $revision, $credentialVersion);

        $this->installations->persistFirstEnrollment(
            installationId: $installationId,
            credentialId: $credentialId,
            credentialVersion: $credentialVersion,
            secretBase64Url: $secret,
            entitlementSnapshot: $snapshot,
            anchorIdentity: isset($remote['anchor_identity']) ? (is_string($remote['anchor_identity']) ? $remote['anchor_identity'] : null) : null,
            controlServerUrl: $this->installations->getEffectiveControlServerUrl(),
            clientVersion: $version,
            entitlementRevision: $revision,
        );

        $presentation = $this->mapper->toPresentation($snapshot);
        $this->appliedHook->afterVerifiedEntitlementApplied($presentation);

        return [
            'installation_id' => $installationId,
            'credential_id' => $credentialId,
            'is_retry' => (bool) ($remote['is_retry'] ?? false),
            'revision' => $revision,
        ];
    }
}
