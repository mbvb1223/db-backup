# db-backup

Dumps MySQL/MariaDB databases with `mysqldump` into gzipped files, uploads them to S3/R2, each place (local folder, every bucket) with its own retention. Several projects can share one connection; per database you choose all tables, only some, or all except some.

Requirements on the server: PHP 8.2+ (CLI, `zlib`, `simplexml`, `curl`), `mysqldump`. Uploads use the [AWS SDK for PHP](https://github.com/aws/aws-sdk-php), installed by Composer (trimmed to S3 only).

## Config

`config.php` (gitignored, copy from `config.example.php`) has three sections:

```php
return [
    'backup' => [                                   // what and how to dump
        'dir' => __DIR__ . '/backups',
        'keep_days' => 14,
        'mysqldump' => 'mysqldump',
        'mysqldump_options' => ['--single-transaction', ...],
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
    ],
    'uploaders' => [...],                            // where dumps go, see Uploaders
    'log' => [                                      // log file and Slack
        'file' => __DIR__ . '/backup.log',
        'slack_webhook' => 'https://hooks.slack.com/services/...',
    ],
];
```

`backup`:

- `dir` – where dumps are written, default `backups/` next to `config.php`. Keep it outside any web root.
- `keep_days` – local dumps older than this are deleted after each run. `0` deletes the new dump as soon as every uploader succeeded (if one fails, it stays until the next good run). Leave it out to keep local dumps forever.
- `mysqldump_options` – flags passed to `mysqldump`.
- `connections` – one entry per server; the key (`main`) is just a label used in the backup path. `databases` also accepts a plain list: `['analytics', 'crm']`.

`log`:

- `file` – every step is logged here (default `backup.log` next to `config.php`), and also printed when you run it by hand.
- `slack_webhook` – optional, see [Slack](#slack).

Output: `<backup.dir>/<connection>/<database>/<database>_YYYYmmdd_HHMMSS.sql.gz`

### MySQL user

```sql
CREATE USER 'backup'@'localhost' IDENTIFIED BY '...';
GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT ON *.* TO 'backup'@'localhost';
```

## Uploaders

Each dump is uploaded by every entry in `uploaders` (leave it empty for local only). Every uploader is tried; any failure makes the run exit non-zero. Each uploader has its own `keep_days`: after a successful upload, older `*.sql.gz` there are deleted (`0` = keep forever).

| `type` | Stores the dump in | Settings |
|---|---|---|
| `s3` | `<bucket>/<prefix>/` (multipart above 16 MB) | `endpoint`, `region`, `bucket`, `key`, `secret`, `prefix`, `keep_days` |

To keep nothing on the server, set `backup.keep_days` to `0`.

R2 is S3-compatible, so it uses `'type' => S3Uploader::TYPE` (`'s3'`):

| | `endpoint` | `region` | `key` / `secret` |
|---|---|---|---|
| Cloudflare R2 | `https://ACCOUNT_ID.r2.cloudflarestorage.com` | `auto` | R2 → *Manage API tokens* → **Object Read & Write** on the bucket |
| AWS S3 | leave empty | bucket's region | IAM user with `s3:PutObject`, `s3:ListBucket`, `s3:DeleteObject` on the bucket |

Set `endpoint`, `bucket`, `key` and `secret` directly in the uploader entry in `config.php`.

## Slack

Each run posts a summary to Slack: green `Backup finished: 3 ok, 0 failed`, or red when something failed. Each error (failed dump, failed upload, config error) is also posted right away. The detailed steps stay in `log.file`. One message per day means the backup ran; no message means it did not.

1. <https://api.slack.com/apps> → **Create New App** → *From scratch*, pick your workspace.
2. **Incoming Webhooks** → turn on → **Add New Webhook to Workspace** → pick a channel.
3. Copy the webhook URL into `config.php` (`shared/config.php` on the server):

```php
'log' => [
    'slack_webhook' => 'https://hooks.slack.com/services/...',
],
```

If Slack is unreachable, the backup still runs; only the message is lost.

## Run

```sh
php index.php   # backs up every database in config.php
```

Exit code is non-zero if any database failed. A database whose previous backup is still running is skipped (and reported as an error).

## Deploy (Deployer)

```sh
cp .env.example .env   # set DEPLOY_HOST, DEPLOY_USER (your machine only)
composer install
vendor/bin/dep deploy
```

Each deploy runs `composer install --no-dev` on the server (Deployer installs Composer into `.dep/` if missing).

Deploys `main` to `/var/www/db-backup`:

- `current/` – the code
- `shared/config.php` – config, kept across deploys
- `shared/backups/` – local dumps, kept across deploys

### First time on the server

```sh
cp /var/www/db-backup/current/config.example.php /var/www/db-backup/shared/config.php
chmod 600 /var/www/db-backup/shared/config.php
nano /var/www/db-backup/shared/config.php
php /var/www/db-backup/current/index.php    # test run
```

Then add the cron (below). Cron points at `current/`, so later deploys need no changes on the server.

## Schedule (cron)

On the server, `crontab -e` (check the PHP path with `which php`):

```
# every day at 02:30
30 2 * * * /usr/bin/php /var/www/db-backup/current/index.php >> /var/www/db-backup/shared/backup.log 2>&1
```

The script writes `backup.log` itself; the redirect only catches PHP crashes that happen before logging starts.

## Restore

Take the dump from `backup.dir`, or download it from the R2/S3 dashboard, then:

```sh
gunzip < project_a_20261005_023000.sql.gz | mysql -u root -p project_a
```

## License

[MIT](LICENSE)
