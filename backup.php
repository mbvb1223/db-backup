#!/usr/bin/env php
<?php

declare(strict_types=1);

use DbBackup\Backup;
use DbBackup\Config;
use DbBackup\Database;
use DbBackup\MysqlDumper;
use DbBackup\S3Remote;

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
    $config = Config::load(getenv('DB_BACKUP_CONFIG') ?: __DIR__ . '/config.php');

    $remotes = [];
    foreach ($config->remotes as $name => $settings) {
        $remotes[$name] = S3Remote::fromConfig($name, $settings, $config->remoteKeepDays);
    }

    $backup = new Backup(
        new MysqlDumper($config->mysqldump, $config->mysqldumpOptions),
        $remotes,
        $config->backupDir,
        $config->keepDays,
    );

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
