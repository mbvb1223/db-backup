# db-backup

Backs up MySQL/MariaDB databases with `mysqldump` into gzipped files, with retention. Several projects can share one connection; per database you choose all tables, only some, or all except some.

Requirements on the server: PHP 8.2+ (CLI, `zlib`, `simplexml`, `curl`), `mysqldump`. Uploads use the [AWS SDK for PHP](https://github.com/aws/aws-sdk-php), installed by Composer (trimmed to S3 only).

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

## Upload (S3 / R2)

R2 is S3-compatible, so both use the same `remotes` entry. Each new dump is also uploaded to every remote as `<bucket>/<prefix>/<connection>/<database>/<file>` (multipart above 16 MB). Every remote is tried; a failure makes the run exit non-zero and the local dump is kept. After a successful upload, `*.sql.gz` older than `remote_keep_days` are deleted from that remote (`0` = keep forever). Leave `remotes` empty to keep dumps local only.

Keys go in `.env` next to `config.php` (gitignored, template in `.env.example`, `shared/.env` on the server). It's loaded before `config.php`, which reads it through `$_ENV`:

```
S3_ACCESS_KEY_ID=...
S3_SECRET_ACCESS_KEY=...
```

| | `endpoint` | `region` | Keys |
|---|---|---|---|
| Cloudflare R2 | `https://ACCOUNT_ID.r2.cloudflarestorage.com` | `auto` | R2 → *Manage API tokens* → **Object Read & Write** on the bucket |
| AWS S3 | leave out | bucket's region | IAM user with `s3:PutObject`, `s3:ListBucket`, `s3:DeleteObject` on the bucket |

## Run

```sh
php backup.php                  # all databases
php backup.php project_a        # one database
php backup.php main/project_a   # one database on a specific connection
```

Exit code is non-zero if any database failed. A lock prevents overlapping runs.

## Deploy (Deployer)

```sh
cp .env.example .env   # set DEPLOY_HOST, DEPLOY_USER
composer install
vendor/bin/dep deploy
```

Each deploy runs `composer install --no-dev` on the server (Deployer installs Composer into `.dep/` if missing).

Deploys `main` to `/var/www/db-backup`:

- `current/` – the code
- `shared/config.php` – config, kept across deploys
- `shared/.env` – upload keys, kept across deploys (copied from `.env.example` on first deploy)
- `shared/backups/` – dumps, kept across deploys

### First time on the server

```sh
cp /var/www/db-backup/current/config.example.php /var/www/db-backup/shared/config.php
chmod 600 /var/www/db-backup/shared/config.php /var/www/db-backup/shared/.env
nano /var/www/db-backup/shared/config.php
nano /var/www/db-backup/shared/.env         # only if uploading
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

From a remote: download the file from the R2/S3 dashboard, then `gunzip` it the same way.

## License

[MIT](LICENSE)
