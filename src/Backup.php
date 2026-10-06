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
        /** @var array<string, Uploader> */
        private array $uploaders,
    ) {
    }

    public function run(Database $db): bool
    {
        $dir = "{$this->config->backupDir}/{$db->id()}";
        $started = microtime(true);
        try {
            $lock = $this->lock($dir);
            $this->deletePartialDumps($dir);
            $file = $this->dumper->dump($db, $dir);
        } catch (Throwable $e) {
            $this->log("FAIL {$db->id()}: {$e->getMessage()}");
            return false;
        }
        $this->log(sprintf('DUMP %s -> %s (%.1f MB, %.1fs)', $db->id(), $file, filesize($file) / 1048576, microtime(true) - $started));

        $allUploaded = $this->upload($db, $file);

        if ($this->config->keepDays !== null) {
            $this->deleteOldDumps($dir, $file);
        }
        if ($this->config->keepDays === 0 && $allUploaded) {
            $this->delete($file);
        }

        return $allUploaded;
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

    private function deletePartialDumps(string $dir): void
    {
        foreach (glob("$dir/*.part") ?: [] as $file) {
            unlink($file);
        }
    }

    private function deleteOldDumps(string $dir, string $newDump): void
    {
        $cutoff = time() - $this->config->keepDays * 86400;
        foreach (glob("$dir/*.sql.gz") ?: [] as $file) {
            if ($file !== $newDump && filemtime($file) < $cutoff) {
                $this->delete($file);
            }
        }
    }

    private function delete(string $file): void
    {
        unlink($file);
        $this->log("DEL  $file");
    }

    private function log(string $message): void
    {
        echo '[' . date('Y-m-d H:i:s') . '] ' . str_replace("\n", ' ', $message) . "\n";
    }
}
