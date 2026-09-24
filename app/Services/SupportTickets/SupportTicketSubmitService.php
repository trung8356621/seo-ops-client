<?php

declare(strict_types=1);

namespace App\Services\SupportTickets;

use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Canonical Support Ticket submit — CORE DB only. No ops-server delivery in this phase.
 */
final class SupportTicketSubmitService
{
    public function __construct(
        private readonly SupportTicketAttachmentService $attachments,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     * @return array{ticket: SupportTicket, message: string}
     */
    public function submit(
        int $userId,
        string $title,
        string $body,
        Request $request,
        array $files = [],
        ?string $pageUrl = null,
        ?string $routeName = null,
        ?string $service = null,
        ?string $connectionHash = null,
    ): array {
        if ($userId <= 0) {
            throw ValidationException::withMessages([
                'title' => __('support_ticket.errors.unauthenticated'),
            ]);
        }

        $storedAttachments = [];
        foreach (array_slice($files, 0, 5) as $file) {
            if ($file instanceof UploadedFile) {
                $storedAttachments[] = $this->attachments->store($file, $userId);
            }
        }

        $safeHash = $this->normalizeConnectionHash($connectionHash);
        $metadata = $this->buildMetadata($request, $pageUrl, $routeName, $service, $safeHash);
        if ($storedAttachments !== []) {
            $metadata['attachments'] = $storedAttachments;
        }

        $ticket = SupportTicket::query()->create([
            'user_id' => $userId,
            'connection_hash' => $safeHash,
            'title' => trim($title),
            'body' => trim($body),
            'status' => SupportTicket::STATUS_QUEUED,
            'metadata' => $metadata,
        ]);

        return [
            'ticket' => $ticket,
            'message' => (string) __('support_ticket.messages.submitted'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(SupportTicket $ticket): array
    {
        $metadata = $ticket->metadata ?? [];
        $attachments = [];
        if (is_array($metadata['attachments'] ?? null)) {
            foreach ($metadata['attachments'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $attachments[] = [
                    'name' => (string) ($row['name'] ?? 'attachment'),
                    'url' => (string) ($row['url'] ?? ''),
                    'mime' => (string) ($row['mime'] ?? ''),
                    'size' => (int) ($row['size'] ?? 0),
                    'is_image' => (bool) ($row['is_image'] ?? false),
                ];
            }
        }

        return [
            'id' => (int) $ticket->id,
            'title' => (string) $ticket->title,
            'body' => (string) $ticket->body,
            'status' => (string) $ticket->status,
            'remote_ticket_id' => $ticket->remote_ticket_id,
            'last_error' => $ticket->last_error,
            'sent_at' => $ticket->sent_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
            'attachments' => $attachments,
            'metadata' => $metadata,
        ];
    }

    private function normalizeConnectionHash(?string $hash): ?string
    {
        $hash = is_string($hash) ? trim($hash) : '';
        if ($hash === '') {
            return null;
        }

        if (preg_match('/^[a-zA-Z0-9]{32,64}$/', $hash) !== 1) {
            return null;
        }

        return $hash;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMetadata(
        Request $request,
        ?string $pageUrl,
        ?string $routeName,
        ?string $service,
        ?string $connectionHash,
    ): array {
        $url = is_string($pageUrl) ? trim($pageUrl) : '';
        if ($url === '') {
            $url = (string) $request->headers->get('Referer', '');
        }

        $safeRoute = is_string($routeName) ? mb_substr(trim($routeName), 0, 200) : '';
        $safeService = is_string($service) ? mb_substr(trim($service), 0, 32) : '';

        return [
            'page_url' => $this->sanitizeUrl($url),
            'route_name' => $safeRoute !== '' ? $safeRoute : null,
            'service' => $safeService !== '' ? $safeService : null,
            'connection_hash' => $connectionHash,
            'app_version' => (string) config('app.version', config('app.env')),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'created_at_client' => now()->toIso8601String(),
        ];
    }

    private function sanitizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return mb_substr($url, 0, 500);
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';
        if ($host === '') {
            return mb_substr($path !== '' ? $path : $url, 0, 500);
        }

        return mb_substr($scheme.'://'.$host.$path, 0, 500);
    }
}
