<?php

declare(strict_types=1);

namespace DbBackup;

use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;

class S3Remote implements Remote
{
    public function __construct(
        private readonly S3Client $s3,
        private readonly string $bucket,
        private readonly string $prefix,
        private readonly int $keepDays,
    ) {
    }

    public function upload(string $file, string $dir): void
    {
        $dir = trim("$this->prefix/$dir", '/');
        // Multipart above 16 MB. No ACL: R2 doesn't support them and new AWS buckets have them disabled.
        $this->s3->upload($this->bucket, "$dir/" . basename($file), Utils::tryFopen($file, 'r'), null);
        if ($this->keepDays <= 0) {
            return;
        }

        $cutoff = time() - $this->keepDays * 86400;
        $objects = $this->s3->getPaginator('ListObjectsV2', ['Bucket' => $this->bucket, 'Prefix' => "$dir/"]);
        foreach ($objects->search('Contents[]') as $object) {
            if (str_ends_with($object['Key'], '.sql.gz') && $object['LastModified']->getTimestamp() < $cutoff) {
                $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $object['Key']]);
            }
        }
    }
}
