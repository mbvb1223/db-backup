<?php

declare(strict_types=1);

namespace DbBackup;

use RuntimeException;

class UploaderFactory
{
    public static function create(string $name, array $settings, int $keepDays): Uploader
    {
        $type = $settings['type'] ?? '';

        return match ($type) {
            's3' => S3Uploader::fromConfig($name, $settings, $keepDays),
            default => throw new RuntimeException("Uploader '$name': unknown type '$type' (supported: s3)"),
        };
    }
}
