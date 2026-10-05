# db-backup

Backs up MySQL/MariaDB databases with `mysqldump` into gzipped files, with retention. Several projects can share one connection; per database you choose all tables, only some, or all except some.

Requirements on the server: PHP 7.4+ (CLI, `zlib`), `mysqldump`.

## Config

`config.php` (gitignored, copy from `config.example.php`):

```php
'connections' => [
    'main' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'user' => 'backup',
        'password' => 'secret',
        'databases' => [
            'project_a' => ['exclude' => ['sessions', 'cache']],  // all tables except these
            'project_b' => ['include' => ['users', 'orders']],    // only these tables
            'project_c' => [],                                    // all tables
        ],
    ],
],
```

- One entry per server under `connections`; the key (`main`) is just a label used in the backup path.
- `databases` also accepts a plain list: `['analytics', 'crm']`.
- `keep_days` – dumps older than this are deleted after a successful new dump (default 14, `0` = keep forever).
- `backup_dir` – defaults to `backups/` next to `config.php`. Keep it outside any web root.
- `options` – flags passed to `mysqldump`.

Output: `<backup_dir>/<connection>/<database>/<database>_YYYYmmdd_HHMMSS.sql.gz`

### MySQL user

```sql
CREATE USER 'backup'@'localhost' IDENTIFIED BY '...';
GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT ON *.* TO 'backup'@'localhost';
```

## Run

```sh
php backup.php                  # all databases
php backup.php project_a        # one database
php backup.php main/project_a   # one database on a specific connection
```

Exit code is non-zero if any database failed. A lock prevents overlapping runs.

## Deploy (Deployer)

Local `.env` (gitignored):

```
DEPLOY_HOST=1.2.3.4
DEPLOY_USER=root
DEPLOY_PORT=22                            # optional, default 22
DEPLOY_IDENTITY_FILE=~/.ssh/id_ed25519    # optional
```

```sh
composer install
vendor/bin/dep deploy
```

Deploys `main` to `/var/www/db-backup`:

- `current/` – the code
- `shared/config.php` – config, kept across deploys
- `shared/backups/` – dumps, kept across deploys

### First time on the server

```sh
cp /var/www/db-backup/current/config.example.php /var/www/db-backup/shared/config.php
chmod 600 /var/www/db-backup/shared/config.php
nano /var/www/db-backup/shared/config.php
php /var/www/db-backup/current/backup.php   # test run
```

Then add the cron (below). Cron points at `current/`, so later deploys need no changes on the server.

## Schedule (cron)

On the server, `crontab -e` (check the PHP path with `which php`):

```
# every day at 02:30
30 2 * * * /usr/bin/php /var/www/db-backup/current/backup.php >> /var/www/db-backup/shared/backup.log 2>&1
```

Different times per project – one line each:

```
30 2 * * *  /usr/bin/php /var/www/db-backup/current/backup.php project_a >> /var/www/db-backup/shared/backup.log 2>&1
0 */6 * * * /usr/bin/php /var/www/db-backup/current/backup.php project_b >> /var/www/db-backup/shared/backup.log 2>&1
```

## Restore

```sh
gunzip < /var/www/db-backup/shared/backups/main/project_a/project_a_20261005_023000.sql.gz | mysql -u root -p project_a
```
