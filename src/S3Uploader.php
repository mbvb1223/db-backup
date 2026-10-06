<?php

declare(strict_types=1);

namespace DbBackup;

use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use RuntimeException;

readonly class S3Uploader
{
    public function __construct(
        private S3Client $s3,
        private string $bucket,
        private string $prefix,
        private int $keepDays,
    ) {
    }

    public static function fromConfig(string $name, array $settings, int $keepDays): self
    {
        foreach (['bucket', 'key', 'secret'] as $key) {
            if (empty($settings[$key])) {
                throw new RuntimeException("Uploader '$name': '$key' is not set (check .env)");
            }
        }

        $options = [
            'version' => 'latest',
            'region' => $settings['region'] ?? 'auto',
            'credentials' => ['key' => $settings['key'], 'secret' => $settings['secret']],
        ];
        if (isset($settings['endpoint'])) {
            $options['endpoint'] = $settings['endpoint'];
        }

        return new self(new S3Client($options), $settings['bucket'], $settings['prefix'] ?? '', $keepDays);
    }

    public function upload(string $file, string $dir): void
    {
        $dir = trim("$this->prefix/$dir", '/');
        $this->s3->upload($this->bucket, "$dir/" . basename($file), Utils::tryFopen($file, 'r'), acl: null);

        if ($this->keepDays > 0) {
            $this->deleteOldDumps($dir);
        }
    }

    private function deleteOldDumps(string $dir): void
    {
        $cutoff = time() - $this->keepDays * 86400;
        $objects = $this->s3->getPaginator('ListObjectsV2', ['Bucket' => $this->bucket, 'Prefix' => "$dir/"]);
        foreach ($objects->search('Contents[]') as $object) {
            if (str_ends_with($object['Key'], '.sql.gz') && $object['LastModified']->getTimestamp() < $cutoff) {
                $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $object['Key']]);
            }
        }
    }
}
