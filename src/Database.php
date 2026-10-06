<?php

declare(strict_types=1);

namespace DbBackup;

use RuntimeException;

readonly class Database
{
    public function __construct(
        public string $connection,
        public string $name,
        public array $server,
        public array $include = [],
        public array $exclude = [],
        public array $excludeData = [],
    ) {
        if ($include && ($exclude || $excludeData)) {
            throw new RuntimeException("$this->connection/$this->name: use either include or exclude/exclude_data, not both");
        }
    }

    public function id(): string
    {
        return "$this->connection/$this->name";
    }
}
