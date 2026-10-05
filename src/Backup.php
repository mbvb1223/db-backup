<?php

declare(strict_types=1);

namespace DbBackup;

use RuntimeException;
use Throwable;

class Backup
{
    /** @param array<string, Remote> $remotes keyed by name, used in log lines */
    public function __construct(
        private readonly MysqlDumper $dumper,
        private readonly array $remotes,
        private readonly Logger $logger,
        private readonly string $backupDir,
        private readonly int $keepDays,
    ) {
    }

    /**
     * @param iterable<array{string, array, string, array}> $databases from Config::databases()
     * @return bool false if any dump or upload failed, or another run holds the lock
     */
    public function run(iterable $databases): bool
    {
        if (!is_dir($this->backupDir) && !mkdir($this->backupDir, 0700, true)) {
            throw new RuntimeException("Cannot create $this->backupDir");
        }
        $lock = fopen("$this->backupDir/.lock", 'c');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            $this->logger->log('Another backup run is in progress, exiting');
            return false;
        }

        $ok = $failed = 0;
        foreach ($databases as [$connName, $server, $db, $rules]) {
            $this->backup($connName, $server, $db, $rules) ? $ok++ : $failed++;
        }
        $this->logger->log("Done: $ok ok, $failed failed");

        return $failed === 0;
    }

    private function backup(string $connName, array $server, string $db, array $rules): bool
    {
        $name = "$connName/$db";
        $started = microtime(true);
        try {
            $file = $this->dumper->dump($server, $db, $rules, "$this->backupDir/$name");
        } catch (Throwable $e) {
            $this->logger->log("FAIL $name: " . $e->getMessage());
            return false;
        }
        $this->logger->log(sprintf('OK   %s -> %s (%s, %.1fs)', $name, $file, $this->formatBytes(filesize($file)), microtime(true) - $started));
        if ($this->keepDays > 0) {
            $this->prune(dirname($file));
        }

        // Every remote is tried even if an earlier one fails; the local dump stays either way.
        $uploaded = true;
        foreach ($this->remotes as $remoteName => $remote) {
            $started = microtime(true);
            try {
                $remote->upload($file, $name);
                $this->logger->log(sprintf('UP   %s -> %s (%.1fs)', $name, $remoteName, microtime(true) - $started));
            } catch (Throwable $e) {
                $uploaded = false;
                $this->logger->log("FAIL $name -> $remoteName: " . $e->getMessage());
            }
        }

        return $uploaded;
    }

    private function prune(string $dir): void
    {
        $cutoff = time() - $this->keepDays * 86400;
        foreach (glob("$dir/*.sql.gz") ?: [] as $f) {
            if (filemtime($f) < $cutoff) {
                unlink($f);
                $this->logger->log("DEL  $f");
            }
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 1) . ' ' . $units[$i];
    }
}
