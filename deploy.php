<?php

namespace Deployer;

require 'recipe/common.php';

set('application', 'db-backup');
set('repository', 'git@github.com:mbvb1223/db-backup.git');
set('keep_releases', 2);

set('shared_files', ['config.php', '.env']);
set('shared_dirs', ['backups']);

$dotenv = \Dotenv\Dotenv::createArrayBacked(__DIR__);
$env = $dotenv->safeLoad();
$dotenv->required(['DEPLOY_HOST', 'DEPLOY_USER'])->notEmpty();

host($env['DEPLOY_HOST'])
    ->set('remote_user', $env['DEPLOY_USER'])
    ->set('port', (int) ($env['DEPLOY_PORT'] ?? 22))
    ->set('branch', 'main')
    ->set('deploy_path', '/var/www/{{application}}')
    ->set('identity_file', $env['DEPLOY_IDENTITY_FILE'] ?? '~/.ssh/id_ed25519');

after('deploy:shared', 'deploy:vendors');
after('deploy:failed', 'deploy:unlock');
