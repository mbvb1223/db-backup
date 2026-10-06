<?php

declare(strict_types=1);

namespace DbBackup;

use Psr\Log\LoggerInterface;
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
        private LoggerInterface $logger,
    ) {
        $this->locks = new LockFactory(new FlockStore());
    }

    public function run(Database $db): bool
    {
        $lock = $this->locks->createLock("db-backup:{$db->id()}");
        if (!$lock->acquire()) {
            $this->logger->error("{$db->id()}: skipped, previous backup is still running");
            return false;
        }

        try {
            return $this->backup($db);
        } catch (Throwable $e) {
            $this->logger->error("{$db->id()}: backup failed: {$e->getMessage()}");
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
        $this->logger->info(sprintf('%s: dumped to %s (%.1f MB, %.1fs)', $db->id(), $file, filesize($file) / 1048576, microtime(true) - $started));

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
                $this->logger->info(sprintf('%s: uploaded to %s (%.1fs)', $db->id(), $name, microtime(true) - $started));
            } catch (Throwable $e) {
                $allUploaded = false;
                $this->logger->error("{$db->id()}: upload to $name failed: {$e->getMessage()}");
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
        $this->logger->info("deleted $file");
    }
}
