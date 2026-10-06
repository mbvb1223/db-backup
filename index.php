<?php

declare(strict_types=1);

use DbBackup\Backup;
use DbBackup\Config;
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

    $uploaders = [];
    foreach ($config->uploaders as $name => $settings) {
        $uploaders[$name] = UploaderFactory::create($name, $settings);
    }

    $backup = new Backup($config, new MysqlDumper($config), $uploaders);
} catch (RuntimeException|InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$exitCode = 0;
foreach ($config->databases as $db) {
    if (!$backup->run($db)) {
        $exitCode = 1;
    }
}
exit($exitCode);
