<?php

use DbBackup\S3Uploader;

return [
    'backup' => [
        'dir' => __DIR__ . '/backups',
        'keep_days' => 14,

        'mysqldump' => 'mysqldump',
        'mysqldump_options' => [
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
    ],

    'uploaders' => [
        'r2' => [
            'type' => S3Uploader::TYPE,
            'endpoint' => 'https://ACCOUNT_ID.r2.cloudflarestorage.com',
            'region' => 'auto',
            'bucket' => 'my-bucket',
            'key' => $_ENV['S3_ACCESS_KEY_ID'] ?? '',
            'secret' => $_ENV['S3_SECRET_ACCESS_KEY'] ?? '',
            'prefix' => 'db-backup',
            'keep_days' => 30,
        ],
    ],

    'log' => [
        'file' => __DIR__ . '/backup.log',
        'slack_webhook' => $_ENV['SLACK_WEBHOOK_URL'] ?? '',
    ],
];
