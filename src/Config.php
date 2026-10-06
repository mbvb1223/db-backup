<?php

declare(strict_types=1);

namespace DbBackup;

use RuntimeException;

readonly class Config
{
    public function __construct(
        public string $backupDir,
        public ?int $keepDays,
        public string $mysqldump,
        public array $mysqldumpOptions,
        public array $databases,
        public array $uploaders,
        public string $logFile,
        public string $slackWebhook,
    ) {
    }

    public static function load(string $file): self
    {
        if (!is_file($file)) {
            throw new RuntimeException("Config not found: $file (copy config.example.php to config.php)");
        }
        $dir = dirname(realpath($file));
        $config = require $file;

        $backup = $config['backup'] ?? [];
        $uploaders = $config['uploaders'] ?? [];
        $log = $config['log'] ?? [];

        $databases = self::databases($backup['connections'] ?? []);
        if (!$databases) {
            throw new RuntimeException("No databases in $file: add them under 'backup' => ['connections' => ...]");
        }
        $keepDays = $backup['keep_days'] ?? null;
        if ($keepDays === 0 && !$uploaders) {
            throw new RuntimeException("'keep_days' => 0 deletes every dump right away: add an uploader or keep them longer");
        }

        return new self(
            backupDir: rtrim($backup['dir'] ?? "$dir/backups", '/'),
            keepDays: $keepDays,
            mysqldump: $backup['mysqldump'] ?? 'mysqldump',
            mysqldumpOptions: $backup['mysqldump_options'] ?? [],
            databases: $databases,
            uploaders: $uploaders,
            logFile: $log['file'] ?? "$dir/backup.log",
            slackWebhook: $log['slack_webhook'] ?? '',
        );
    }

    private static function databases(array $connections): array
    {
        $databases = [];
        foreach ($connections as $connection => $server) {
            foreach ($server['databases'] ?? [] as $name => $tables) {
                if (is_int($name)) {
                    [$name, $tables] = [$tables, []];
                }
                $databases[] = new Database((string) $connection, (string) $name, $server, $tables['include'] ?? [], $tables['exclude'] ?? []);
            }
        }

        return $databases;
    }
}
