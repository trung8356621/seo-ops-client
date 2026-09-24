<?php

declare(strict_types=1);

namespace App\Services\SupportTickets;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Client-owned ticket attachments — same metadata shape as TeamChatAttachmentService,
 * without SEO overview / request context coupling.
 */
final class SupportTicketAttachmentService
{
    private const MAX_FILES = 5;

    private const MAX_FILE_SIZE_MB = 5;

    /** @var list<string> */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'];

    /**
     * @return array{
     *     path: string,
     *     name: string,
     *     mime: string,
     *     size: int,
     *     url: string,
     *     is_image: bool
     * }
     */
    public function store(UploadedFile $file, int $ownerId): array
    {
        if ($ownerId <= 0) {
            throw ValidationException::withMessages([
                'file' => __('support_ticket.errors.owner_missing'),
            ]);
        }

        $this->assertAllowed($file);

        $extension = $this->resolveExtension($file);
        $filename = Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');
        $directory = 'uploads/support-tickets/'.$ownerId;
        $path = $file->storeAs($directory, $filename, 'public');

        if ($path === false) {
            throw ValidationException::withMessages([
                'file' => __('support_ticket.errors.store_failed'),
            ]);
        }

        $mime = (string) ($file->getMimeType() ?? 'application/octet-stream');
        $size = (int) $file->getSize();

        return [
            'path' => $path,
            'name' => $this->sanitizeOriginalName($file->getClientOriginalName()),
            'mime' => $mime,
            'size' => $size,
            'url' => Storage::disk('public')->url($path),
            'is_image' => str_starts_with($mime, 'image/'),
        ];
    }

    /**
     * @return array{
     *     allowed_extensions: list<string>,
     *     max_file_size_mb: int,
     *     max_file_size_bytes: int,
     *     max_files: int
     * }
     */
    public function clientConfig(): array
    {
        return [
            'allowed_extensions' => self::ALLOWED_EXTENSIONS,
            'max_file_size_mb' => self::MAX_FILE_SIZE_MB,
            'max_file_size_bytes' => self::MAX_FILE_SIZE_MB * 1024 * 1024,
            'max_files' => self::MAX_FILES,
        ];
    }

    private function assertAllowed(UploadedFile $file): void
    {
        $extension = $this->resolveExtension($file);
        $maxBytes = self::MAX_FILE_SIZE_MB * 1024 * 1024;
        $size = (int) $file->getSize();

        if ($size <= 0) {
            throw ValidationException::withMessages([
                'file' => __('support_ticket.errors.file_empty'),
            ]);
        }

        if ($size > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => __('support_ticket.errors.file_too_large', ['mb' => self::MAX_FILE_SIZE_MB]),
            ]);
        }

        $normalized = $extension === 'jpeg' ? 'jpg' : $extension;
        $allowed = array_map(
            static fn (string $ext): string => $ext === 'jpeg' ? 'jpg' : $ext,
            self::ALLOWED_EXTENSIONS,
        );

        if ($normalized === '' || ! in_array($normalized, $allowed, true)) {
            throw ValidationException::withMessages([
                'file' => __('support_ticket.errors.file_type', [
                    'types' => implode(', ', self::ALLOWED_EXTENSIONS),
                ]),
            ]);
        }
    }

    private function resolveExtension(UploadedFile $file): string
    {
        $fromName = strtolower((string) $file->getClientOriginalExtension());
        if ($fromName !== '') {
            return $fromName;
        }

        return match ((string) ($file->getMimeType() ?? '')) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'application/pdf' => 'pdf',
            default => '',
        };
    }

    private function sanitizeOriginalName(?string $name): string
    {
        $clean = trim((string) $name);
        if ($clean === '') {
            return 'attachment';
        }

        return Str::limit($clean, 180, '');
    }
}
