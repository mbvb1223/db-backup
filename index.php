<?php

declare(strict_types=1);

use DbBackup\Backup;
use DbBackup\Config;
use DbBackup\LoggerFactory;
use DbBackup\MysqlDumper;
use DbBackup\UploaderFactory;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!is_file(__DIR__ . '/vendor/autoload.php')) {
    fwrite(STDERR, "Run `composer install --no-dev` first\n");
    exit(1);
}
require __DIR__ . '/vendor/autoload.php';

try {
    $config = Config::load(__DIR__ . '/config.php');
} catch (RuntimeException|InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$logger = LoggerFactory::create($config);

try {
    $uploaders = [];
    foreach ($config->uploaders as $name => $settings) {
        $uploaders[$name] = UploaderFactory::create($name, $settings);
    }
} catch (RuntimeException|InvalidArgumentException $e) {
    $logger->error($e->getMessage());
    exit(1);
}

$backup = new Backup($config, new MysqlDumper($config), $uploaders, $logger);

$logger->info(sprintf('Backup started: %d databases', count($config->databases)));
$ok = [];
$failed = [];
foreach ($config->databases as $db) {
    if ($backup->run($db)) {
        $ok[] = $db->id();
    } else {
        $failed[] = $db->id();
    }
}

$summary = sprintf('Backup finished: %d ok, %d failed', count($ok), count($failed));
if ($ok) {
    $summary .= "\nOK: " . implode(', ', $ok);
}
if ($failed) {
    $summary .= "\nFailed: " . implode(', ', $failed);
}
$failed ? $logger->error($summary) : $logger->notice($summary);

exit($failed ? 1 : 0);
