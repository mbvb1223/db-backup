<?php

declare(strict_types=1);

namespace DbBackup;

interface Remote
{
    /** Uploads $file into $dir, then deletes expired dumps there. */
    public function upload(string $file, string $dir): void;
}
