<?php

declare(strict_types=1);

namespace DbBackup;

interface Uploader
{
    public function upload(string $file, string $dir): void;
}
