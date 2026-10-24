<?php

declare(strict_types=1);

namespace App\Services\H1\Notifications;

use App\Models\H1ClientNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Persist client-facing H1 entitlement notifications (idempotent by notification_id).
 */
final class ClientNotificationStore
{
    /**
     * @param  array<string, mixed>  $payload  Wire client_notification object
     */
    public function recordFromCommand(string $installationId, string $commandId, array $payload): void
    {
        if (! Schema::hasTable('h1_client_notifications')) {
            return;
        }

        $notificationId = (string) ($payload['id'] ?? $commandId);
        if ($notificationId === '') {
            return;
        }

        $message = trim((string) ($payload['message'] ?? ''));
        if ($message === '') {
            return;
        }

        $action = (string) ($payload['action'] ?? '');
        $serviceKey = isset($payload['service_key']) && is_string($payload['service_key'])
            ? $payload['service_key']
            : null;

        $existing = H1ClientNotification::query()
            ->where('notification_id', $notificationId)
            ->first();
        if ($existing instanceof H1ClientNotification) {
            return;
        }

        $issuedAt = null;
        if (is_string($payload['issued_at'] ?? null) && $payload['issued_at'] !== '') {
            try {
                $issuedAt = Carbon::parse((string) $payload['issued_at']);
            } catch (\Throwable) {
                $issuedAt = now();
            }
        }

        $row = H1ClientNotification::query()->create([
            'notification_id' => $notificationId,
            'installation_id' => $installationId,
            'command_id' => $commandId,
            'action' => $action !== '' ? $action : 'change',
            'service_key' => $serviceKey,
            'message' => mb_substr($message, 0, 2000),
            'status_before' => isset($payload['status_before']) ? (string) $payload['status_before'] : null,
            'status_after' => isset($payload['status_after']) ? (string) $payload['status_after'] : null,
            'entitlement_revision' => (int) ($payload['entitlement_revision'] ?? 0),
            'issued_at' => $issuedAt ?? now(),
            'display_active' => true,
            'superseded_at' => null,
        ]);

        $this->applyDisplayRules($row);
    }

    private function applyDisplayRules(H1ClientNotification $row): void
    {
        $action = (string) $row->action;
        $serviceKey = $row->service_key;

        // Resume / grant / renew / upgrade clear prior active warning for the same service.
        if (in_array($action, ['resume', 'grant', 'renew', 'upgrade'], true) && is_string($serviceKey) && $serviceKey !== '') {
            H1ClientNotification::query()
                ->where('installation_id', $row->installation_id)
                ->where('service_key', $serviceKey)
                ->where('display_active', true)
                ->where('id', '!=', $row->id)
                ->whereIn('action', ['suspend', 'revoke', 'quota_change'])
                ->update([
                    'display_active' => false,
                    'superseded_at' => now(),
                ]);
        }

        // Informational resume/grant messages stay in history but are not warning banners.
        if (in_array($action, ['resume', 'grant', 'renew', 'upgrade'], true)) {
            $row->forceFill([
                'display_active' => false,
                'superseded_at' => now(),
            ])->save();
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeForInstallation(string $installationId): array
    {
        if (! Schema::hasTable('h1_client_notifications')) {
            return [];
        }

        return H1ClientNotification::query()
            ->where('installation_id', $installationId)
            ->where('display_active', true)
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (H1ClientNotification $n): array => $this->toArray($n))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentHistory(string $installationId, int $limit = 20): array
    {
        if (! Schema::hasTable('h1_client_notifications')) {
            return [];
        }

        return H1ClientNotification::query()
            ->where('installation_id', $installationId)
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (H1ClientNotification $n): array => $this->toArray($n))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(H1ClientNotification $n): array
    {
        return [
            'id' => $n->notification_id,
            'action' => $n->action,
            'service_key' => $n->service_key,
            'message' => $n->message,
            'status_before' => $n->status_before,
            'status_after' => $n->status_after,
            'entitlement_revision' => (int) $n->entitlement_revision,
            'issued_at' => $n->issued_at?->toIso8601String(),
            'display_active' => (bool) $n->display_active,
        ];
    }
}
