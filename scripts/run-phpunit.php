<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/scripts/ensure-test-tools.php';

$memory = getenv('TEST_MEMORY_LIMIT') ?: '512M';
$phpBinary = PHP_BINARY;
$phpunit = $root.'/vendor/phpunit/phpunit/phpunit';
$args = array_slice($argv, 1);

// Default to project phpunit.xml when caller did not pass --configuration.
$hasConfiguration = false;
foreach ($args as $arg) {
    if ($arg === '--configuration' || str_starts_with($arg, '--configuration=')) {
        $hasConfiguration = true;
        break;
    }
}

$command = [$phpBinary, '-d', 'memory_limit='.$memory, $phpunit];
$configuration = null;
if (! $hasConfiguration && is_file($root.'/phpunit.xml')) {
    $command[] = '--configuration';
    $configuration = $root.'/phpunit.xml';
    $command[] = $configuration;
} elseif ($hasConfiguration) {
    foreach ($args as $index => $arg) {
        if ($arg === '--configuration') {
            $configuration = $args[$index + 1] ?? '(missing)';
            break;
        }
        if (str_starts_with($arg, '--configuration=')) {
            $configuration = substr($arg, strlen('--configuration='));
            break;
        }
    }
}

array_push($command, ...$args);

$cmd = implode(' ', array_map(static function (string $part): string {
    return escapeshellarg($part);
}, $command));

fwrite(STDOUT, "[run-phpunit] memory_limit={$memory}\n");
fwrite(STDOUT, '[run-phpunit] config='.($configuration ?? '(phpunit default)')."\n");
fwrite(STDOUT, '[run-phpunit] APP_ENV='.(getenv('APP_ENV') ?: '(set by configuration)')."\n");

passthru($cmd, $exitCode);
exit($exitCode);
