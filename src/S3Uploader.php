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
        private ?int $keepDays,
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

        return new self(new S3Client($options), $settings['bucket'], trim($settings['prefix'] ?? '', '/'), $settings['keep_days'] ?? null);
    }

    public function upload(string $file, string $dir): void
    {
        $dir = $this->key("$dir/");
        $key = $dir . basename($file);
        $this->s3->upload($this->bucket, $key, Utils::tryFopen($file, 'r'), acl: null);

        if ($this->keepDays !== null) {
            $this->deleteOldDumps($dir, $key);
        }
    }

    private function key(string $name): string
    {
        return $this->prefix === '' ? $name : "$this->prefix/$name";
    }

    private function deleteOldDumps(string $dir, string $newKey): void
    {
        $cutoff = time() - $this->keepDays * 86400;
        $objects = $this->s3->getPaginator('ListObjectsV2', ['Bucket' => $this->bucket, 'Prefix' => $dir, 'Delimiter' => '/']);
        foreach ($objects->search('Contents[]') as $object) {
            if ($object['Key'] !== $newKey && str_ends_with($object['Key'], '.sql.gz') && $object['LastModified']->getTimestamp() < $cutoff) {
                $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $object['Key']]);
            }
        }
    }
}
