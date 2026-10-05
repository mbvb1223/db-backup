#!/usr/bin/env php
<?php

declare(strict_types=1);

use Aws\S3\S3Client;
use DbBackup\Backup;
use DbBackup\Config;
use DbBackup\Logger;
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

    $remotes = array_map(
        fn (array $r) => new S3Remote(new S3Client($r['client']), $r['bucket'], $r['prefix'], $config->remoteKeepDays()),
        $config->remotes()
    );
    $backup = new Backup(
        new MysqlDumper($config->mysqldump(), $config->options()),
        $remotes,
        new Logger(),
        $config->backupDir(),
        $config->keepDays()
    );

    exit($backup->run($config->databases(array_slice($argv, 1))) ? 0 : 1);
} catch (RuntimeException|InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
