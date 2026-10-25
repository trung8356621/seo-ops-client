<?php

declare(strict_types=1);

namespace App\Services\H1\Enrollment;

use App\Services\Gateway\EntitlementAppliedHook;
use App\Services\Gateway\VerifiedEntitlementSnapshot;
use App\Services\H1\Exceptions\H1EnrollmentException;
use App\Services\H1\Persistence\InstallationStateRepository;
use App\Services\H1\Sites\AdminSiteStatusPolicy;
use App\Services\H1\Sites\PrimaryAnchorSiteService;
use App\Services\H1\Snapshot\EntitlementSnapshotMapper;
use Illuminate\Support\Facades\Schema;

final class EnrollmentConsumer
{
    public function __construct(
        private readonly EnrollmentTransport $transport,
        private readonly InstallationStateRepository $installations,
        private readonly EntitlementSnapshotMapper $mapper,
        private readonly EntitlementAppliedHook $appliedHook,
        private readonly PrimaryAnchorSiteService $primarySites,
        private readonly AdminSiteStatusPolicy $sitePolicy,
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
        $localAnchor = $this->primarySites->currentAnchorDomain();

        $remote = $this->transport->consume($token, $version, $localAnchor);
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

        return $this->persistValidatedEnrollment(
            remote: $remote,
            installationId: $installationId,
            credentialId: $credentialId,
            credentialVersion: $credentialVersion,
            secret: $secret,
            version: $version,
            localAnchor: $localAnchor,
            replaceExisting: false,
        );
    }

    /**
     * Connect this Client to a different OPS-SERVER Installation Key.
     * Validates the new enrollment with OPS-SERVER first; on failure the current binding is untouched.
     * Does not run Installer / App Initializer / Golden Configuration bootstrap.
     *
     * @return array{installation_id: string, credential_id: string, previous_installation_id: string, revision: int}
     */
    public function replaceInstallationKey(string $enrollmentToken, ?string $clientVersion = null): array
    {
        $token = trim($enrollmentToken);
        if ($token === '' || ! preg_match('/^tok_[A-Za-z0-9_-]{8,124}$/', $token)) {
            throw new H1EnrollmentException('invalid_receipt', 'Enrollment token format is invalid.');
        }

        if (! Schema::hasTable('h1_installations')) {
            throw new H1EnrollmentException('schema_missing', 'H1 license tables are not migrated.');
        }

        $existing = $this->installations->currentInstallation();
        if ($existing === null || ! $this->installations->isEnrolled()) {
            throw new H1EnrollmentException(
                'not_enrolled',
                'Client is not enrolled yet. Use first-time enrollment instead of key replacement.',
            );
        }

        $previousInstallationId = (string) $existing->installation_id;
        $version = $clientVersion ?: (string) config('app.version', config('configuration-sync.client_version', '0.0.0'));
        $localAnchor = $this->primarySites->currentAnchorDomain();

        // Validate with OPS-SERVER BEFORE mutating local identity/credential.
        // Pass previous_installation_id so OPS can ACK detach of the old key.
        $remote = $this->transport->consume($token, $version, $localAnchor, $previousInstallationId);
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

        if ($installationId === $previousInstallationId) {
            // Same Installation — treat as idempotent refresh via standard enroll path semantics.
            $result = $this->persistValidatedEnrollment(
                remote: $remote,
                installationId: $installationId,
                credentialId: $credentialId,
                credentialVersion: $credentialVersion,
                secret: $secret,
                version: $version,
                localAnchor: $localAnchor,
                replaceExisting: false,
            );

            return [
                'installation_id' => $result['installation_id'],
                'credential_id' => $result['credential_id'],
                'previous_installation_id' => $previousInstallationId,
                'revision' => $result['revision'],
            ];
        }

        $result = $this->persistValidatedEnrollment(
            remote: $remote,
            installationId: $installationId,
            credentialId: $credentialId,
            credentialVersion: $credentialVersion,
            secret: $secret,
            version: $version,
            localAnchor: $localAnchor,
            replaceExisting: true,
            previousInstallationId: $previousInstallationId,
        );

        return [
            'installation_id' => $result['installation_id'],
            'credential_id' => $result['credential_id'],
            'previous_installation_id' => $previousInstallationId,
            'revision' => $result['revision'],
        ];
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array{installation_id: string, credential_id: string, is_retry: bool, revision: int}
     */
    private function persistValidatedEnrollment(
        array $remote,
        string $installationId,
        string $credentialId,
        int $credentialVersion,
        string $secret,
        string $version,
        ?string $localAnchor,
        bool $replaceExisting,
        ?string $previousInstallationId = null,
    ): array {
        $entitlement = is_array($remote['entitlement'] ?? null) ? $remote['entitlement'] : [];
        $revision = (int) ($remote['entitlement_revision'] ?? ($entitlement['revision'] ?? 1));
        $snapshot = $this->mapper->normalizeEntitlement($entitlement, $installationId, $revision, $credentialVersion);

        $remoteAnchor = isset($remote['anchor_identity']) && is_string($remote['anchor_identity'])
            ? strtolower(trim($remote['anchor_identity']))
            : null;
        if ($remoteAnchor === '') {
            $remoteAnchor = null;
        }

        // Local primary Site is authoritative when present; never replace a different bound local Anchor.
        $anchorIdentity = $localAnchor ?? $remoteAnchor;
        if ($localAnchor !== null && $remoteAnchor !== null && $localAnchor !== $remoteAnchor) {
            $anchorIdentity = $localAnchor;
        }

        if ($replaceExisting) {
            $installation = $this->installations->replaceInstallationBinding(
                previousInstallationId: (string) $previousInstallationId,
                installationId: $installationId,
                credentialId: $credentialId,
                credentialVersion: $credentialVersion,
                secretBase64Url: $secret,
                entitlementSnapshot: $snapshot,
                anchorIdentity: $anchorIdentity,
                controlServerUrl: $this->installations->getEffectiveControlServerUrl(),
                clientVersion: $version,
                entitlementRevision: $revision,
            );
        } else {
            $installation = $this->installations->persistFirstEnrollment(
                installationId: $installationId,
                credentialId: $credentialId,
                credentialVersion: $credentialVersion,
                secretBase64Url: $secret,
                entitlementSnapshot: $snapshot,
                anchorIdentity: $anchorIdentity,
                controlServerUrl: $this->installations->getEffectiveControlServerUrl(),
                clientVersion: $version,
                entitlementRevision: $revision,
            );
        }

        // Re-bind Anchor metadata only — must not create duplicate websites.
        $this->sitePolicy->bindAnchorFromInstallation($installation->fresh() ?? $installation);
        $this->primarySites->syncInstallationBinding();

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
