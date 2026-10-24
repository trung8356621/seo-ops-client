<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$outDir = $argv[1] ?? storage_path('app/backups/support-tickets-removal-2026-10-24');
if (! is_dir($outDir)) {
    mkdir($outDir, 0775, true);
}

$exists = Schema::hasTable('support_tickets');
$rows = $exists ? DB::table('support_tickets')->orderBy('id')->get() : collect();
$payload = [
    'backed_up_at' => now()->toIso8601String(),
    'connection' => config('database.default'),
    'table' => 'support_tickets',
    'row_count' => $rows->count(),
    'rows' => $rows->map(static fn ($r) => (array) $r)->all(),
];
file_put_contents(
    $outDir.DIRECTORY_SEPARATOR.'support_tickets.json',
    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

$attachmentPaths = [];
foreach ($rows as $r) {
    $meta = json_decode((string) ($r->metadata ?? '{}'), true);
    if (! is_array($meta)) {
        continue;
    }
    foreach (($meta['attachments'] ?? []) as $a) {
        if (! is_array($a) || empty($a['path']) || ! is_string($a['path'])) {
            continue;
        }
        $path = $a['path'];
        $ownedByTicketPrefix = str_starts_with($path, 'uploads/support-tickets/');
        $attachmentPaths[] = [
            'ticket_id' => $r->id,
            'path' => $path,
            'exists_on_public_disk' => Storage::disk('public')->exists($path),
            'delete_eligible' => $ownedByTicketPrefix,
            'reason' => $ownedByTicketPrefix
                ? 'path under uploads/support-tickets/'
                : 'not under uploads/support-tickets/ — preserve (may belong to team-chat or other module)',
        ];
    }
}

file_put_contents(
    $outDir.DIRECTORY_SEPARATOR.'attachments-inventory.json',
    json_encode([
        'backed_up_at' => now()->toIso8601String(),
        'attachments' => $attachmentPaths,
        'public_disk_support_tickets_dir_files' => collect(Storage::disk('public')->allFiles('uploads/support-tickets'))->values()->all(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

echo json_encode([
    'outDir' => $outDir,
    'row_count' => $rows->count(),
    'attachments' => count($attachmentPaths),
    'support_tickets_dir_files' => count(Storage::disk('public')->allFiles('uploads/support-tickets')),
], JSON_UNESCAPED_SLASHES).PHP_EOL;
