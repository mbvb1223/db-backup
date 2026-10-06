<?php

declare(strict_types=1);

namespace DbBackup;

use Dotenv\Dotenv;
use RuntimeException;

readonly class Config
{
    public function __construct(
        public string $backupDir,
        public ?int $keepDays,
        public string $mysqldump,
        public array $mysqldumpOptions,
        public array $uploaders,
        public array $databases,
    ) {
    }

    public static function load(string $file): self
    {
        if (!is_file($file)) {
            throw new RuntimeException("Config not found: $file (copy config.example.php to config.php)");
        }
        Dotenv::createImmutable(dirname($file))->safeLoad();
        $config = require $file;

        $keepDays = $config['keep_days'] ?? null;
        if ($keepDays === 0 && empty($config['uploaders'])) {
            throw new RuntimeException("'keep_days' => 0 deletes every dump right away: add an uploader or keep them longer");
        }

        return new self(
            backupDir: rtrim($config['backup_dir'] ?? dirname($file) . '/backups', '/'),
            keepDays: $keepDays,
            mysqldump: $config['mysqldump'] ?? 'mysqldump',
            mysqldumpOptions: $config['options'] ?? [],
            uploaders: $config['uploaders'] ?? [],
            databases: self::databases($config['connections']),
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
