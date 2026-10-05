<?php

return [
    'backup_dir' => __DIR__ . '/backups',

    // Old dumps are deleted only after a successful new one. 0 = keep forever.
    'keep_days' => 14,

    'mysqldump' => 'mysqldump',

    // Each new dump is uploaded to every remote (S3 or R2) as <prefix>/<connection>/<database>/<file>.
    // Keys come from .env next to this file (see README). [] = local only.
    'remotes' => [
        'r2' => [
            // AWS S3: drop 'endpoint' and set 'region' to the bucket's region, e.g. 'ap-southeast-1'
            'endpoint' => 'https://ACCOUNT_ID.r2.cloudflarestorage.com',
            'region' => 'auto',
            'bucket' => 'my-bucket',
            'key' => getenv('S3_ACCESS_KEY_ID'),
            'secret' => getenv('S3_SECRET_ACCESS_KEY'),
            'prefix' => 'db-backup',
        ],
    ],
    // Remote dumps older than this are deleted after a successful upload. 0 = keep forever.
    'remote_keep_days' => 30,

    'options' => [
        '--single-transaction',
        '--quick',
        '--routines',
        '--triggers',
        '--events',
        '--hex-blob',
        '--no-tablespaces',
        '--default-character-set=utf8mb4',
    ],

    'connections' => [
        'main' => [
            'host' => '127.0.0.1',
            'port' => 3306,
            'user' => 'backup',
            'password' => 'secret',
            'databases' => [
                'project_a' => ['exclude' => ['sessions', 'cache', 'jobs']],
                'project_b' => ['include' => ['users', 'orders', 'products']],
                'project_c' => [],
            ],
        ],

        'reporting' => [
            'host' => 'db2.internal',
            'user' => 'backup',
            'password' => 'secret',
            'databases' => ['analytics', 'crm'],
        ],
    ],
];
