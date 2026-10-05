# db-backup

Dumps MySQL/MariaDB databases with `mysqldump`, gzipped, with retention.

## Setup

```sh
cp config.example.php config.php
chmod 600 config.php        # contains passwords
php backup.php              # test run
```

Config: one entry per connection, list its databases under `databases`. Per database:

- `[]` – all tables
- `['exclude' => ['sessions', 'cache']]` – all tables except these
- `['include' => ['users', 'orders']]` – only these tables

Output: `backups/<connection>/<database>/<database>_YYYYmmdd_HHMMSS.sql.gz`

## Run

```sh
php backup.php                  # all databases
php backup.php project_a        # one database
php backup.php main/project_a   # one database on a specific connection
```

Exit code is non-zero if any database failed.

## Schedule (cron)

`crontab -e`:

```
# every day at 02:30
30 2 * * * /usr/bin/php /var/www/db-backup/current/backup.php >> /var/www/db-backup/shared/backup.log 2>&1
```

Different times per project – one line each:

```
30 2 * * * /usr/bin/php /var/www/db-backup/current/backup.php project_a >> /var/www/db-backup/shared/backup.log 2>&1
0 */6 * * * /usr/bin/php /var/www/db-backup/current/backup.php project_b >> /var/www/db-backup/shared/backup.log 2>&1
```

## Restore

```sh
gunzip < backups/main/project_a/project_a_20261005_023000.sql.gz | mysql -u root -p project_a
```

## MySQL user

```sql
CREATE USER 'backup'@'localhost' IDENTIFIED BY '...';
GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT ON *.* TO 'backup'@'localhost';
```

## Deploy (Deployer)

Create `.env` (gitignored) with `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PORT` (default 22), `DEPLOY_IDENTITY_FILE` (default `~/.ssh/id_ed25519`). Then:

```sh
composer install
vendor/bin/dep deploy          # clones repo, links shared/config.php + shared/backups
```

First time only, on the server:

```sh
cp /var/www/db-backup/current/config.example.php /var/www/db-backup/shared/config.php
chmod 600 /var/www/db-backup/shared/config.php
nano /var/www/db-backup/shared/config.php
php /var/www/db-backup/current/backup.php   # test run
crontab -e                                  # add the line from "Schedule (cron)"
```

Cron points at `current/`, so later deploys need no cron changes.
