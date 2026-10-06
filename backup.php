#!/usr/bin/env php
<?php

declare(strict_types=1);

use DbBackup\Backup;
use DbBackup\Config;
use DbBackup\Database;
use DbBackup\MysqlDumper;
use DbBackup\S3Uploader;

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
        $uploaders[$name] = S3Uploader::fromConfig($name, $settings, $config->uploadKeepDays);
    }

    $backup = new Backup($config, new MysqlDumper($config), $uploaders);

    $requested = array_slice($argv, 1);
    $databases = array_filter(
        $config->databases,
        fn (Database $db) => !$requested || in_array($db->name, $requested, true) || in_array($db->id(), $requested, true),
    );

    exit($backup->run($databases) ? 0 : 1);
} catch (RuntimeException|InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
