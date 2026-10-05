<?php

declare(strict_types=1);

namespace DbBackup;

class Logger
{
    public function log(string $message): void
    {
        // One line per event; AWS SDK errors span several
        echo '[' . date('Y-m-d H:i:s') . '] ' . str_replace("\n", ' ', $message) . "\n";
    }
}
