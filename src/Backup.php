<?php

declare(strict_types=1);

namespace DbBackup;

use RuntimeException;
use Throwable;

readonly class Backup
{
    public function __construct(
        private MysqlDumper $dumper,
        private array $remotes,
        private string $backupDir,
        private int $keepDays,
    ) {
    }

    public function run(array $databases): bool
    {
        if (!is_dir($this->backupDir) && !mkdir($this->backupDir, 0700, true)) {
            throw new RuntimeException("Cannot create $this->backupDir");
        }
        $lock = fopen("$this->backupDir/.lock", 'c');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            $this->log('Another backup run is in progress, exiting');
            return false;
        }

        $failed = 0;
        foreach ($databases as $db) {
            if (!$this->backup($db)) {
                $failed++;
            }
        }
        $this->log(sprintf('Done: %d ok, %d failed', count($databases) - $failed, $failed));

        return $failed === 0;
    }

    private function backup(Database $db): bool
    {
        $started = microtime(true);
        try {
            $file = $this->dumper->dump($db, "$this->backupDir/{$db->id()}");
        } catch (Throwable $e) {
            $this->log("FAIL {$db->id()}: {$e->getMessage()}");
            return false;
        }
        $this->log(sprintf('OK   %s -> %s (%.1f MB, %.1fs)', $db->id(), $file, filesize($file) / 1048576, microtime(true) - $started));

        if ($this->keepDays > 0) {
            $this->deleteOldDumps(dirname($file));
        }

        $allUploaded = true;
        foreach ($this->remotes as $name => $remote) {
            $started = microtime(true);
            try {
                $remote->upload($file, $db->id());
                $this->log(sprintf('UP   %s -> %s (%.1fs)', $db->id(), $name, microtime(true) - $started));
            } catch (Throwable $e) {
                $allUploaded = false;
                $this->log("FAIL {$db->id()} -> $name: {$e->getMessage()}");
            }
        }

        return $allUploaded;
    }

    private function deleteOldDumps(string $dir): void
    {
        $cutoff = time() - $this->keepDays * 86400;
        foreach (glob("$dir/*.sql.gz") ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $this->log("DEL  $file");
            }
        }
    }

    private function log(string $message): void
    {
        echo '[' . date('Y-m-d H:i:s') . '] ' . str_replace("\n", ' ', $message) . "\n";
    }
}
