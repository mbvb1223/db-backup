<?php

return [
    'backup_dir' => __DIR__ . '/backups',

    // Old dumps are deleted only after a successful new one. 0 = keep forever.
    'keep_days' => 14,

    'mysqldump' => 'mysqldump',

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
