<?php

namespace Deployer;

require 'recipe/common.php';

set('application', 'db-backup');
set('repository', 'git@github.com:mbvb1223/db-backup.git');
set('keep_releases', 3);

// config.php is gitignored, it lives in shared/ and is symlinked into each release
set('shared_files', ['config.php']);
set('shared_dirs', ['backups']);

$env = is_file(__DIR__ . '/.env') ? parse_ini_file(__DIR__ . '/.env') : [];
foreach (['DEPLOY_HOST', 'DEPLOY_USER'] as $key) {
    if (empty($env[$key])) {
        throw new \RuntimeException("Set $key in .env");
    }
}

host($env['DEPLOY_HOST'])
    ->set('remote_user', $env['DEPLOY_USER'])
    ->set('port', (int) ($env['DEPLOY_PORT'] ?? 22))
    ->set('branch', 'main')
    ->set('deploy_path', '/var/www/{{application}}')
    ->set('identity_file', $env['DEPLOY_IDENTITY_FILE'] ?? '~/.ssh/id_ed25519');

after('deploy:failed', 'deploy:unlock');
