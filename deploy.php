<?php

namespace Deployer;

require 'recipe/common.php';
require 'contrib/crontab.php';

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

host('vps')
    ->setHostname($env['DEPLOY_HOST'])
    ->setRemoteUser($env['DEPLOY_USER'])
    ->setPort((int) ($env['DEPLOY_PORT'] ?? 22))
    ->setDeployPath($env['DEPLOY_PATH'] ?? '~/db-backup');

add('crontab:jobs', [
    '30 2 * * * {{bin/php}} {{current_path}}/backup.php >> {{deploy_path}}/shared/backup.log 2>&1',
]);

desc('Uploads local config.php to the server');
task('config:upload', function () {
    run('mkdir -p {{deploy_path}}/shared');
    upload(__DIR__ . '/config.php', '{{deploy_path}}/shared/config.php');
    run('chmod 600 {{deploy_path}}/shared/config.php');
});

// deploy:shared creates an empty config.php on first deploy; don't install the cron with it
task('config:check', function () {
    if (!test('[ -s {{deploy_path}}/shared/config.php ]')) {
        throw error('shared/config.php is empty, run: vendor/bin/dep config:upload');
    }
});

after('deploy:shared', 'config:check');
after('deploy:success', 'crontab:sync');
after('deploy:failed', 'deploy:unlock');
