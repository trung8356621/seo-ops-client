<?php

declare(strict_types=1);

return [
    'step_title' => 'Background cron tasks (aaPanel)',
    'step_description' => 'Download the standard 5-job aaPanel JSON and import it on the server. This step is optional — you can continue without verifying cron.',
    'download_json' => 'Download aaPanel configuration (JSON)',
    'download_hint' => 'Exports exactly 5 SEO-OPS jobs. Does not include SSL renewal, backups, or aaPanel system tasks.',
    'duplicate_warning' => 'If aaPanel already lists these SEO-OPS job names, do not import again — the panel does not deduplicate by name.',
    'guide_title' => 'Import into aaPanel',
    'guide_1' => 'Open aaPanel → Cron.',
    'guide_2' => 'Import the JSON if your panel version supports it (BT/aaPanel 9.1+).',
    'guide_3' => 'Confirm exactly 5 SEO-OPS jobs were created (no duplicates).',
    'guide_4' => 'Run once and check logs under storage/logs/.',
    'continue' => 'Continue: Complete & summary',
    'back' => 'Back to step 3',
];
