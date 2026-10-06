# db-backup

Dumps MySQL/MariaDB databases with `mysqldump` into gzipped files and hands each one to the configured uploaders (a local folder, S3, R2), each with its own retention. Several projects can share one connection; per database you choose all tables, only some, or all except some.

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
- `options` – flags passed to `mysqldump`.
- `tmp_dir` – where each dump is written before upload, then deleted (default: system temp dir).

File name: `<connection>/<database>/<database>_YYYYmmdd_HHMMSS.sql.gz`

### MySQL user

```sql
CREATE USER 'backup'@'localhost' IDENTIFIED BY '...';
GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT ON *.* TO 'backup'@'localhost';
```

## Uploaders

Each dump goes to every entry in `uploaders`, then the temporary file is deleted. Each uploader has its own `keep_days`: after a successful upload, older `*.sql.gz` there are deleted (`0` = keep forever). Every uploader is tried; any failure makes the run exit non-zero.

| `type` | Stores the dump in | Settings |
|---|---|---|
| `local` | `<dir>/<connection>/<database>/` | `dir`, `keep_days` |
| `s3` | `<bucket>/<prefix>/<connection>/<database>/` (multipart above 16 MB) | `endpoint`, `region`, `bucket`, `key`, `secret`, `prefix`, `keep_days` |

To keep nothing on the server, leave out the `local` uploader.

R2 is S3-compatible, so it uses `'type' => 's3'`:

| | `endpoint` | `region` | Keys |
|---|---|---|---|
| Cloudflare R2 | `https://ACCOUNT_ID.r2.cloudflarestorage.com` | `auto` | R2 → *Manage API tokens* → **Object Read & Write** on the bucket |
| AWS S3 | leave out | bucket's region | IAM user with `s3:PutObject`, `s3:ListBucket`, `s3:DeleteObject` on the bucket |

Keys go in `.env` next to `config.php` (gitignored, template in `.env.example`, `shared/.env` on the server). It's loaded before `config.php`, which reads it through `$_ENV`:

```
S3_ACCESS_KEY_ID=...
S3_SECRET_ACCESS_KEY=...
```

## Run

```sh
php index.php   # backs up every database in config.php
```

Exit code is non-zero if any database failed. A per-database lock skips a database whose previous backup is still running.

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
- `shared/backups/` – dumps of the `local` uploader, kept across deploys

### First time on the server

```sh
cp /var/www/db-backup/current/config.example.php /var/www/db-backup/shared/config.php
chmod 600 /var/www/db-backup/shared/config.php /var/www/db-backup/shared/.env
nano /var/www/db-backup/shared/config.php
nano /var/www/db-backup/shared/.env         # only for s3 uploaders
php /var/www/db-backup/current/index.php    # test run
```

Then add the cron (below). Cron points at `current/`, so later deploys need no changes on the server.

## Schedule (cron)

On the server, `crontab -e` (check the PHP path with `which php`):

```
# every day at 02:30
30 2 * * * /usr/bin/php /var/www/db-backup/current/index.php >> /var/www/db-backup/shared/backup.log 2>&1
```

## Restore

Download the dump from the R2/S3 dashboard (or take it from the `local` uploader's `dir`), then:

```sh
gunzip < project_a_20261005_023000.sql.gz | mysql -u root -p project_a
```

## License

[MIT](LICENSE)
