<?php

declare(strict_types=1);

namespace DbBackup;

use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use RuntimeException;

readonly class S3Uploader implements Uploader
{
    public const TYPE = 's3';

    public function __construct(
        private S3Client $s3,
        private string $bucket,
        private string $prefix,
        private int $keepDays,
    ) {
    }

    public static function fromConfig(string $name, array $settings): self
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
        if (!empty($settings['endpoint'])) {
            $options['endpoint'] = $settings['endpoint'];
        }

        return new self(new S3Client($options), $settings['bucket'], trim($settings['prefix'] ?? '', '/'), $settings['keep_days'] ?? 0);
    }

    public function upload(string $file): void
    {
        $this->s3->upload($this->bucket, $this->key(basename($file)), Utils::tryFopen($file, 'r'), acl: null);

        if ($this->keepDays > 0) {
            $this->deleteOldDumps();
        }
    }

    private function key(string $name): string
    {
        return $this->prefix === '' ? $name : "$this->prefix/$name";
    }

    private function deleteOldDumps(): void
    {
        $cutoff = time() - $this->keepDays * 86400;
        $objects = $this->s3->getPaginator('ListObjectsV2', ['Bucket' => $this->bucket, 'Prefix' => $this->key(''), 'Delimiter' => '/']);
        foreach ($objects->search('Contents[]') as $object) {
            if (str_ends_with($object['Key'], '.sql.gz') && $object['LastModified']->getTimestamp() < $cutoff) {
                $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $object['Key']]);
            }
        }
    }
}
