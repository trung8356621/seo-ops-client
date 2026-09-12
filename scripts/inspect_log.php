<?php

declare(strict_types=1);

$logFile = __DIR__ . '/../storage/logs/laravel.log';
if (!file_exists($logFile)) {
    echo "Log file not found!\n";
    exit;
}

$lines = file($logFile);
$total = count($lines);
echo "Total lines in laravel.log: $total\n";

// Search backwards for 429 or OpenRouter or nemotron
$matches = [];
for ($i = $total - 1; $i >= 0 && count($matches) < 40; $i--) {
    $line = $lines[$i];
    if (stripos($line, '429') !== false || stripos($line, 'ai.routing') !== false || stripos($line, 'openrouter') !== false) {
        $matches[] = "Line " . ($i + 1) . ": " . trim($line);
    }
}

foreach (array_reverse($matches) as $m) {
    echo substr($m, 0, 300) . "\n";
}
