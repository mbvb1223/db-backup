<?php

namespace Deployer;

require 'recipe/common.php';

set('application', 'db-backup');
set('repository', 'git@github.com:mbvb1223/db-backup.git');
set('keep_releases', 2);

set('shared_files', ['config.php', '.env']);
set('shared_dirs', ['backups']);

$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();
$dotenv->required(['DEPLOY_HOST', 'DEPLOY_USER'])->notEmpty();

host($_ENV['DEPLOY_HOST'])
    ->set('remote_user', $_ENV['DEPLOY_USER'])
    ->set('port', (int) ($_ENV['DEPLOY_PORT'] ?? 22))
    ->set('branch', 'main')
    ->set('deploy_path', '/var/www/{{application}}')
    ->set('identity_file', $_ENV['DEPLOY_IDENTITY_FILE'] ?? '~/.ssh/id_ed25519');

after('deploy:shared', 'deploy:vendors');
after('deploy:failed', 'deploy:unlock');
