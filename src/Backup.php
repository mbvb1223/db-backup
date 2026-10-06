<?php

declare(strict_types=1);

namespace DbBackup;

use RuntimeException;
use Throwable;

readonly class Backup
{
    public function __construct(
        private Config $config,
        private MysqlDumper $dumper,
        private array $uploaders,
    ) {
    }

    public function run(Database $db): bool
    {
        $workDir = "{$this->config->tmpDir}/db-backup/{$db->id()}";
        $started = microtime(true);
        try {
            $lock = $this->lock($workDir);
            $this->deleteLeftoverDumps($workDir);
            $file = $this->dumper->dump($db, $workDir);
        } catch (Throwable $e) {
            $this->log("FAIL {$db->id()}: {$e->getMessage()}");
            return false;
        }
        $this->log(sprintf('DUMP %s (%.1f MB, %.1fs)', $db->id(), filesize($file) / 1048576, microtime(true) - $started));

        try {
            return $this->upload($db, $file);
        } finally {
            unlink($file);
        }
    }

    private function lock(string $dir)
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            throw new RuntimeException("cannot create $dir");
        }
        $lock = fopen("$dir/.lock", 'c');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('previous backup is still running');
        }

        return $lock;
    }

    private function deleteLeftoverDumps(string $dir): void
    {
        foreach (glob("$dir/*.sql.gz*") ?: [] as $file) {
            unlink($file);
        }
    }

    private function upload(Database $db, string $file): bool
    {
        $allUploaded = true;
        foreach ($this->uploaders as $name => $uploader) {
            $started = microtime(true);
            try {
                $uploader->upload($file, $db->id());
                $this->log(sprintf('UP   %s -> %s (%.1fs)', $db->id(), $name, microtime(true) - $started));
            } catch (Throwable $e) {
                $allUploaded = false;
                $this->log("FAIL {$db->id()} -> $name: {$e->getMessage()}");
            }
        }

        return $allUploaded;
    }

    private function log(string $message): void
    {
        echo '[' . date('Y-m-d H:i:s') . '] ' . str_replace("\n", ' ', $message) . "\n";
    }
}
