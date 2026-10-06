<?php

declare(strict_types=1);

namespace DbBackup;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Throwable;

readonly class Backup
{
    private LockFactory $locks;

    public function __construct(
        private Config $config,
        private MysqlDumper $dumper,
        /** @var array<string, Uploader> */
        private array $uploaders,
    ) {
        $this->locks = new LockFactory(new FlockStore());
    }

    public function run(Database $db): bool
    {
        $lock = $this->locks->createLock("db-backup:{$db->id()}");
        if (!$lock->acquire()) {
            $this->log("FAIL {$db->id()}: previous backup is still running");
            return false;
        }

        try {
            return $this->backup($db);
        } catch (Throwable $e) {
            $this->log("FAIL {$db->id()}: {$e->getMessage()}");
            return false;
        } finally {
            $lock->release();
        }
    }

    private function backup(Database $db): bool
    {
        $dir = "{$this->config->backupDir}/{$db->id()}";
        $this->deletePartialDumps($dir);

        $started = microtime(true);
        $file = $this->dumper->dump($db, $dir);
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
