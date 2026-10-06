<?php

declare(strict_types=1);

namespace DbBackup;

use RuntimeException;

readonly class LocalUploader implements Uploader
{
    public function __construct(
        private string $dir,
        private int $keepDays,
    ) {
    }

    public static function fromConfig(string $name, array $settings): self
    {
        if (empty($settings['dir'])) {
            throw new RuntimeException("Uploader '$name': 'dir' is not set");
        }

        return new self(rtrim($settings['dir'], '/'), $settings['keep_days'] ?? 0);
    }

    public function upload(string $file, string $dir): void
    {
        $targetDir = "$this->dir/$dir";
        if (!is_dir($targetDir) && !mkdir($targetDir, 0700, true)) {
            throw new RuntimeException("cannot create $targetDir");
        }

        $target = "$targetDir/" . basename($file);
        if (!copy($file, "$target.part") || !rename("$target.part", $target)) {
            throw new RuntimeException("cannot copy to $target");
        }

        if ($this->keepDays > 0) {
            $this->deleteOldDumps($targetDir);
        }
    }

    private function deleteOldDumps(string $dir): void
    {
        $cutoff = time() - $this->keepDays * 86400;
        foreach (glob("$dir/*.sql.gz") ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }
}
