<?php

declare(strict_types=1);

return [
    /**
     * Max SEO portable-import ZIP size in megabytes.
     * Used by Filament FileUpload (kilobytes) and inspectPackage filesize checks.
     */
    'max_upload_mb' => max(1, (int) env('CLIENT_TRANSFER_MAX_UPLOAD_MB', 2048)),
];
