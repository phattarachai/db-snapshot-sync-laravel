# DB Snapshot Sync

[![Latest Version on Packagist](https://img.shields.io/packagist/v/phattarachai/db-snapshot-sync-laravel.svg?style=flat-square)](https://packagist.org/packages/phattarachai/db-snapshot-sync-laravel)
[![Tests](https://img.shields.io/github/actions/workflow/status/phattarachai/db-snapshot-sync-laravel/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/phattarachai/db-snapshot-sync-laravel/actions/workflows/run-tests.yml?query=branch%3Amain)
[![Code Style](https://img.shields.io/github/actions/workflow/status/phattarachai/db-snapshot-sync-laravel/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/phattarachai/db-snapshot-sync-laravel/actions/workflows/fix-php-code-style-issues.yml?query=branch%3Amain)
[![PHP Version](https://img.shields.io/packagist/dependency-v/phattarachai/db-snapshot-sync-laravel/php?style=flat-square&label=php&logo=php&logoColor=white)](https://packagist.org/packages/phattarachai/db-snapshot-sync-laravel)
![Laravel Version](https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20?style=flat-square&logo=laravel&logoColor=white)
[![Total Downloads](https://img.shields.io/packagist/dt/phattarachai/db-snapshot-sync-laravel.svg?style=flat-square)](https://packagist.org/packages/phattarachai/db-snapshot-sync-laravel)

Rebuild a Laravel developer's local database from production in one command. Extends
[`spatie/laravel-db-snapshots`](https://github.com/spatie/laravel-db-snapshots) with the three
pieces every project otherwise reimplements: a driver-aware dump **sanitizer**, a **sync**
orchestrator, and an **export** command the source box runs over ssh. Nothing listens on the source
and no token exists: whoever can ssh into the box and run artisan as the app's user can pull, and
those people can already read its `.env` and database.

```
snapshot:sync --fresh
  → ssh prod-box 'cd /var/www/app/current && sudo -n -u www-data php artisan snapshot:export --fresh'
  → the .sql.gz (or .sql.zst) streams back over ssh, checked against its size and sha256
  → snapshot:sed  (strip directives that reject on a local client)
  → load: psql, in one transaction (PostgreSQL)
          the mysql client, streamed (MySQL/MariaDB)
```

Supports **PostgreSQL** and **MySQL/MariaDB**.

On the source side it also keeps those snapshots **off-site**: `snapshot:backup` copies them to any
Flysystem disk (S3/Spaces, Google Drive, a NAS over sftp), retention goes by age instead of file
count, `snapshot:backup-check` raises an event when a copy goes stale, and `snapshot:drill` proves the
off-site copy actually restores. See [Off-site backup & retention](#off-site-backup--retention).

## Why

`spatie/laravel-db-snapshots` dumps and loads, but a dump made by a production `pg_dump` /
`mysqldump` rejects on a developer's locally-installed client — psql meta-commands (`\restrict`,
`SET transaction_timeout`), owner/grant lines, or MySQL `DEFINER=` clauses that need `SUPER`.
This package strips those, and adds the download side so the whole "copy prod down to local" loop
is one command instead of copy-pasted per project.

## Install

```bash
composer require phattarachai/db-snapshot-sync-laravel
php artisan db-snapshot-sync:install
```

The installer publishes the config, writes the source env keys, and prints the source-side
snippets you paste into `config/db-snapshots.php`, `config/filesystems.php`, `config/database.php`
(dump exclusions) and the scheduler. It requires `spatie/laravel-db-snapshots` and a `snapshots`
filesystem disk on **both** ends, and the package deployed on the source box. Then point each
source at its box: see [SSH sources](#ssh-sources).

### Laravel Boost

The package ships a [Laravel Boost](https://github.com/laravel/boost) skill, `db-snapshot-sync`, and a
one-paragraph guideline. Run `php artisan boost:install` (or `boost:update`) in the app, and its coding
agent learns `snapshot:sync`, its options, the environment guard, source-side setup, troubleshooting,
and to run `php artisan migrate` after a load. It then answers "pull the prod DB to local" without
searching `php artisan list`.

## Commands

- **`snapshot:sed {name?} {--latest} {--connection=} {--driver=}`** — sanitize a snapshot on the
  disk so it re-imports cleanly. Postgres mutates in place; MySQL writes a separate
  `.sanitized.sql.gz` (`.sanitized.sql.zst` for a zstd snapshot). Without `--connection`/`--driver` it uses the rules for the engine named in
  the dump's header, falling back to the default connection's driver.
- **`snapshot:sync {--fresh} {--source=production} {--connection=} {--no-load} {--keep-raw}`** —
  pull the latest snapshot from a source, sanitize, and load into the local DB. Runs in every
  environment except `production` unless configured otherwise; see
  [Where `snapshot:sync` may run](#where-snapshotsync-may-run).
- **`snapshot:export {--fresh} {--disk=} {--pattern=}`** — runs on the source box (`snapshot:sync`
  starts it over ssh): writes the newest snapshot, or with `--fresh` a new one it then deletes, to
  stdout, and its size and sha256 to stderr. It refuses to write to a terminal. `--disk` and
  `--pattern` (a glob on the file name) stream from another disk, e.g. an app's own dump format.

- **`snapshot:restore {name?} {--latest} {--connection=} {--role=} {--keep-tables} {--force}`** —
  restore a snapshot from the disk safely: streamed through psql in one transaction (or the mysql
  client), never read whole into memory. See [Restoring a snapshot safely](#restoring-a-snapshot-safely).
- **`snapshot:backup`**, **`snapshot:prune {--days=} {--dry-run}`**, **`snapshot:backup-check`**,
  **`snapshot:drill {--disk=} {--connection=}`**: off-site backup, local retention, staleness
  check and restore drill. See [Off-site backup & retention](#off-site-backup--retention).

Start with a dry run once the sources are set:

```bash
php artisan snapshot:sync --no-load     # download + sanitize, no DB clobber
php artisan snapshot:sync               # full sync from production
```

## Cross-engine and multi-connection sync

By default `snapshot:sync` sanitizes for, and loads into, the app's **default** connection. When a
source's engine differs from that — say production is still MySQL while the app's local default
has moved to PostgreSQL — name the local connection the dump belongs to, either per run:

```bash
php artisan snapshot:sync --source=production --connection=mysql
```

or once, on the source in `config/db-snapshot-sync.php` (the plain-string form keeps working):

```php
'sources' => [
    'production' => [
        'ssh' => 'prod-box',
        'path' => '/var/www/shop/current',
        'connection' => 'mysql',
    ],
    'dev' => ['ssh' => 'dev-box', 'path' => '/var/www/shop'],   // PG → PG, default connection
],
```

`--connection` wins over the source's `connection`, which wins over `database.default`. The
connection's driver picks the sanitizer (so a MySQL dump gets its `DEFINER=` lines stripped even
when the default is `pgsql`), and the dump is loaded into that connection.

**Engine guard.** Before sanitizing, the sync reads the dump's header (`-- MySQL dump` /
`-- MariaDB dump` vs `-- PostgreSQL database dump`, plain, gzip or zstd). If it names a different engine
from the target connection's driver, the sync stops with an error — nothing is sanitized or
loaded, and the download stays on the disk. Without this, the load would drop every table on the
wrong database first and only then fail on the foreign SQL. A dump whose
header names neither engine is let through. `snapshot:sed --connection`/`--driver` applies the
same check.

**Loading into a non-default connection in-process.** A `pg_dump` leaves `search_path = ''` on the
session it ran through, so `snapshot:sync` and `snapshot:restore` purge the target connection after
the load, and `snapshot:sync` puts the caller's default connection back. A command that calls either
and keeps querying sees its own default and a fresh session.

The source side is unchanged: `snapshot:export --fresh` dumps the source app's own default connection.

## SSH sources

A source names the box the way you already reach it:

```php
'sources' => [
    'production' => [
        'ssh' => env('DB_SNAPSHOT_SYNC_PROD_SSH', 'prod-box'),          // ~/.ssh/config Host alias
        'path' => env('DB_SNAPSHOT_SYNC_PROD_PATH', '/var/www/shop/current'),
        'run_as' => env('DB_SNAPSHOT_SYNC_PROD_RUN_AS', 'www-data'),    // null when you log in as the app's user
        // 'php' => 'php8.5',                                           // the box's PHP, default `ssh.php`
        // 'ssh_options' => ['ProxyJump' => 'bastion'],
    ],
],
```

None of it is a secret, so commit the hosts and paths and keep the env vars for a developer whose
alias differs. `snapshot:sync` then runs

```
ssh -o BatchMode=yes … prod-box "cd '/var/www/shop/current' && 'sudo' '-n' '-u' 'www-data' 'env' 'HOME=/tmp' 'php' '-d' 'display_errors=stderr' 'artisan' 'snapshot:export' …"
```

and writes ssh's stdout straight into the snapshots disk, with no copy in PHP's memory. The export's
last stderr line carries the file's size and sha256; a transfer that doesn't match, a non-zero exit
or a missing line deletes the download before anything is sanitized or loaded. Every other stderr
line is shown as `source: …`.

**Who can pull.** Exactly the people who can ssh in and run artisan as the app's user. Revoking a
laptop's ssh key revokes its pulls; there is no shared secret to rotate. The box's own database
login does the dump, so no extra database role is needed.

**`run_as` and sudo.** When you log in as yourself and the app runs as `www-data`, artisan runs through
`sudo -n` (never a prompt), the same way `rh artisan` does. Anyone with full sudo already has that.
For someone who should pull snapshots but not have root, a sudoers rule for one wrapper is enough:

```bash
# /usr/local/bin/snapshot-export (root-owned, 0755)
#!/bin/sh
cd /var/www/shop/current && exec env HOME=/tmp php -d display_errors=stderr artisan snapshot:export "$@"
```

```
# /etc/sudoers.d/snapshot-export   (visudo -f)
%snapshot-pull ALL=(www-data) NOPASSWD: /usr/local/bin/snapshot-export
```

and the source names the wrapper instead of a path: `'wrapper' => '/usr/local/bin/snapshot-export'`
(with `run_as`). The sync then runs `sudo -n -u www-data /usr/local/bin/snapshot-export [--fresh]`.

**The client.** The OpenSSH client that macOS, Linux, WSL2 and Windows 10+ ship, with your own
`~/.ssh/config`, keys and agent. `BatchMode=yes` means a missing key fails at once instead of
prompting. A box behind a bastion or an IAP proxy works through `ProxyJump` / `ProxyCommand` in the
alias or in `ssh_options`. Set `DB_SNAPSHOT_SYNC_SSH` (config `ssh.binary`) when `ssh` isn't on `PATH`.

**On the box.** Deploy this package there (1.6+) with its `snapshot:create` schedule. Nothing else:
no route, no env flag, no token. Snapshot files only need to be readable by the app's user; keep
the snapshots directory out of other users' reach (`chmod 2750` on the directory, `0640` files).

**Your own export.** An app with another dump (say a `pg_dump -Fc` of a second database on its own
disk) can stream it with `snapshot:export --disk=<disk> --pattern='<glob>'`, or write its own export
command around `Transfer\SnapshotStreamer` and print `ExportResult::toLine()` to stderr. The
client side is `app(Transfer\SshPuller::class)->pull(SshSource::fromConfig($key, $entry), ['my:export'], $localPath)`,
which returns the verified `ExportResult` or throws `TransferFailed` with a readable reason.

### Moving off the HTTP API

1. Deploy 1.6 to the source box.
2. Give each source an `ssh` host and `path` (and `run_as`). An `ssh` host wins over a `url` on the
   same source, so both can sit there while you switch.
3. Pull once with `snapshot:sync --no-load` to check.
4. On the box, unset `DB_SNAPSHOT_SYNC_API` and `INTERNAL_API_TOKEN`; locally, drop
   `INTERNAL_API_TOKEN`, the `*_URL` keys and `DB_SNAPSHOT_SYNC_CA_BUNDLE`.

## The HTTP API (deprecated)

Removed in 2.0. A source that is a plain URL string, or has a `url` and no `ssh`, still pulls
through it and prints a deprecation warning. Set `DB_SNAPSHOT_SYNC_API=true` (and the shared
`INTERNAL_API_TOKEN`) on the source to expose:

| Method | Route | Purpose |
|---|---|---|
| GET | `/internal/snapshots` | list servable snapshots |
| POST | `/internal/snapshots` | create a fresh one synchronously (`--fresh`) |
| GET | `/internal/snapshots/latest` | stream the newest download |

`EnsureInternalToken` 404s the whole group in `local` and checks a `hash_equals` bearer token
otherwise; the routes carry `throttle:5,1`. The consumer sends the matching token from its `.env`.
A source URL is a base URL, so it may carry a path (`'url' => 'https://example.com/api'`).

## Restoring a snapshot safely

```bash
php artisan snapshot:restore --latest            # newest snapshot on the disk, asks first
php artisan snapshot:restore 2026-11-14_04-00-01 --force
php artisan snapshot:restore --latest --connection=mysql --keep-tables
```

`snapshot:restore` loads a snapshot from the snapshots disk into a connection, and `snapshot:sync`'s
load step uses the same code. Use it instead of spatie's `snapshot:load`. That command's default mode
drops every table first, then reads the whole dump into one PHP string and runs it as one statement.
A few hundred MB of `--inserts` dump runs out of memory, or an error tracker serialising that statement
as an SQL breadcrumb does, and the database is left empty.

**PostgreSQL** goes through **`psql`**, pg_dump's own parser, in one transaction:
- It first drops every object of the connection's schemas that no extension owns: tables, views,
  sequences, types and functions. A dump therefore restores over a database that already holds them.
- Then it sends the dump with `ON_ERROR_STOP=1`. `COMMIT` is sent only after the whole file has been
  read and it ends with pg_dump's `-- PostgreSQL database dump complete` trailer.
- A failed statement, a read error or a truncated file rolls back to the database as it was.
- Extensions are never dropped. The dump's `COMMENT ON EXTENSION` lines, which only the extension's
  owner may run, are skipped on the way in, together with the sanitizer's `drop_prefixes`.
  `CREATE EXTENSION IF NOT EXISTS` stays, and does nothing when the extension is there.
- `--role=app_owner` runs `SET ROLE` after the drop, so the restored objects belong to that role.
- A custom-format archive (`pg_dump -Fc`) is refused, because it needs pg_restore.

spatie's statement splitter is also wrong for pg_dump output. It treats a backslash inside a `'…'`
literal as an escape, so a value such as `I\'ve` throws it off, and it silently drops everything after
it. psql has no such problem.

**MySQL/MariaDB** goes through the **`mysql`** client. Each mysqldump table replaces itself
(`DROP TABLE IF EXISTS` + `CREATE TABLE`), and the tables the dump doesn't create are dropped after it
loaded. MySQL commits every DDL statement, so a failure part-way leaves the tables before it replaced
and the rest as they were, never an empty database.

**What both engines share:**
- The dump streams in 1 MB chunks: 274 MB of SQL restores with about 8 MB of PHP memory.
- No statement passes through Laravel's query events, so an error tracker records no SQL breadcrumbs.
- Credentials reach the client as `PG*` variables or a `0600` option file, never in argv.
- A `.sql`, `.sql.gz` or `.sql.zst` file all work.
- A dump of the other engine is refused before anything is dropped.

**Where it runs.** Anywhere, after asking (or with `--force`). In `restore.denied_environments`
(default `production`) it refuses unless that environment is also listed in
`DB_SNAPSHOT_SYNC_RESTORE_ALLOWED_ENVIRONMENTS` on the box *and* `--force` is passed.

**Prohibit them like `migrate:fresh`.** `snapshot:restore` and `snapshot:sync` use Laravel's
`Prohibitable`, as `db:wipe` and `migrate:fresh` do. Prohibit them next to the app's own line:

```php
// AppServiceProvider::boot()
DB::prohibitDestructiveCommands($this->app->isProduction());
DbSnapshotSync::prohibitDestructiveCommands($this->app->isProduction());   // snapshot:restore + snapshot:sync
// or one at a time: RestoreCommand::prohibit(...), SyncCommand::prohibit(...)
```

A prohibited command prints Laravel's "This command is prohibited from running in this environment."
and stops before it touches anything. The package's guards apply on top and can't be switched off from
code. `snapshot:sync` refuses `sync.denied_environments`, and `snapshot:restore` refuses
`restore.denied_environments` unless the box lists itself in `DB_SNAPSHOT_SYNC_RESTORE_ALLOWED_ENVIRONMENTS`
and `--force` is passed.

| Option | Effect |
|---|---|
| `name` / `--latest` | A snapshot on the disk by name (with or without the extension), or the newest by the time in its name. Files in `backup.protect` (a committed baseline) are never picked. |
| `--connection=` | Restore into this connection instead of the default one. |
| `--role=` | PostgreSQL: `SET ROLE` before the dump. |
| `--keep-tables` | Don't drop the existing objects first (config `load.drop-tables`). |
| `--force` | Don't ask. Required without a terminal and in a protected environment. |

The machine running it needs the client: set `DB_SNAPSHOT_SYNC_PSQL` / `DB_SNAPSHOT_SYNC_MYSQL` when
`psql` / `mysql` isn't on `PATH` (e.g. `/opt/homebrew/opt/libpq/bin/psql`).

## Configuration

`config/db-snapshot-sync.php` covers the disk name, the compression format, the sources and the
ssh client, load flags, the `psql` binary, the per-driver sanitizer rules (add a prefix/`sed`
expression when a new dump quirk appears), and the export's reject-list (`export.reject`: never
send a schema-only baseline or a `.sanitized.` intermediate).

`dump.exclude_table_data` lists framework caches and transient queues (`cache`, `sessions`,
`jobs`, `pulse_*`, `telescope_*`, …) whose **data** is skipped when the source builds a sync
snapshot — the schema is still dumped, and a project's own rollback snapshots are untouched. This
keeps the dev copy small; real domain tables are always included. The Postgres sanitizer streams
the dump line-by-line, so a multi-GB snapshot sanitizes without loading the whole file into memory.

`dump.rows_per_insert` (default `1000`) batches the sync snapshot into multi-row INSERTs.
laravel-db-snapshots forces `--inserts` because its loader restores through PDO (which can't stream
COPY), so a large table otherwise restores one round-trip per row — a million-row table can take
tens of minutes. Batching cuts that to a couple of statements' worth of work. Applies to the sync
snapshot only; the committed baseline stays single-row so it keeps diffing line-by-line.

On **PostgreSQL** the exclusion is pg_dump's `--exclude-table-data`. **MySQL/MariaDB**'s mysqldump
has no per-table "schema but no data" flag (`--ignore-table` drops the schema too), so the sync
dump runs in two passes: the main dump `--ignore-table`s the excluded tables, then a `--no-data`
dump of just those tables (the ones that exist, checked in `information_schema`) is appended to the
snapshot as a second gzip member (a second frame in a zstd snapshot, which every zstd reader
handles). `gunzip`, `zcat` and spatie's `snapshot:load --stream` read the
whole file; PHP's `gzdecode()` (spatie's non-streamed load) stops at the first member, so load a raw
MySQL sync snapshot with `--stream`. `snapshot:sync` already does both: the MySQL sanitizer
recompresses the file into a single member.

`dump.rows_per_insert` is PostgreSQL-only (it becomes `--rows-per-insert`); mysqldump's default
`--extended-insert` already batches rows.

### Where `snapshot:sync` may run

`snapshot:sync` overwrites the local database, so it refuses some environments. By default it runs
everywhere except `production`: local, staging, a UAT box. Each project can change that with
comma-separated env vars (entries may use `*` wildcards, as `app()->environment()` does):

```dotenv
DB_SNAPSHOT_SYNC_DENIED_ENVIRONMENTS=production,uat   # refuse these, run everywhere else
DB_SNAPSHOT_SYNC_ALLOWED_ENVIRONMENTS=local,staging   # or: run only in these
```

A non-empty `allowed_environments` decides alone, and `denied_environments` is then ignored. Both
are under `sync` in the published config.

Upgrading from 1.3 or earlier: a config published by those versions hard-codes
`'allowed_environments' => ['local', 'development']`, and that allow-list still wins. To get the new
default, replace the `sync` block with the one in the package's
[`config/db-snapshot-sync.php`](config/db-snapshot-sync.php).

### Compression: gzip or zstd

`snapshot:create --compress` writes gzip (`.sql.gz`) by default, as spatie does. Set
`DB_SNAPSHOT_SYNC_COMPRESSION=zstd` (config `compression.format`) to write `.sql.zst` instead; it
applies to the scheduled snapshot and to the one `snapshot:export --fresh` builds alike. zstd matches
repeats across the whole dump where gzip's 32 KB window cannot, so a pg_dump shrinks far more: one
4.17 GB dump came to 251 MB with gzip and 21 MB with `zstd -10 -T0`, in about a second.

Reading never depends on the setting. Every command sniffs a file's format from its magic bytes, so
a disk holding both formats lists, serves, syncs, sanitizes, loads, backs up and drills either. The
package extends spatie's `SnapshotFactory`, `SnapshotRepository` and `Snapshot` so that
`snapshot:create`, `snapshot:load` (streamed or not), `snapshot:list`, `snapshot:delete` and
`snapshot:cleanup` handle `.sql.zst` too; gzip and plain snapshots take spatie's own code path.

PHP has no built-in zstd, so the [`zstd` CLI](https://github.com/facebook/zstd) does the work: on
the source, and on every machine that syncs a zstd snapshot (`brew install zstd`,
`apt install zstd`). It is looked up on `PATH`, then in `/opt/homebrew/bin`, `/usr/local/bin` and
`/usr/bin`, which covers a launchd job or php-fpm whose `PATH` is only `/usr/bin:/bin`. Set
`DB_SNAPSHOT_SYNC_ZSTD` to point elsewhere. `DB_SNAPSHOT_SYNC_ZSTD_LEVEL` (default `10`; above `19`
adds `--ultra`) and `DB_SNAPSHOT_SYNC_ZSTD_THREADS` (default `0`, every core) tune the writer. A
corrupt or truncated `.sql.zst` fails the load or sanitize instead of loading part of it.

### HTTP sources with an incomplete TLS chain (deprecated)

Some edges serve the leaf certificate but omit an intermediate. A browser or system `curl` fetches
the missing intermediate via the certificate's AIA extension, but PHP's cURL does not, so the sync
download fails with `cURL error 60: unable to get local issuer certificate`. Point
`DB_SNAPSHOT_SYNC_CA_BUNDLE` (config `http.ca_bundle`) at the missing intermediate's PEM (absolute,
or relative to the app base path) and the client appends it to the system trust store for the
download only — the chain still fully verifies. Leave it unset for ordinary sources.

## Off-site backup & retention

spatie's `snapshot:create` writes to one disk, usually on the same machine as the database, and
`snapshot:cleanup --keep=N` deletes by **file count**, so a burst of deploy restore points shortens
the window. These four commands put a copy somewhere else and age both sides by **time**.

| Command | Job | What it does |
|---|---|---|
| `snapshot:backup` | `BackupSnapshots` | uploads local snapshots to every target disk, keeps `daily/` + `weekly/`, prunes the targets |
| `snapshot:prune {--days=} {--dry-run}` | `PruneSnapshots` | deletes local snapshots older than `local_days` (replaces `snapshot:cleanup --keep`) |
| `snapshot:backup-check` | `CheckSnapshotBackup` | dispatches `SnapshotBackupStale` / `SnapshotBackupHealthy` per target |
| `snapshot:drill {--disk=} {--connection=}` | `RunRestoreDrill` | restores the newest off-site copy into a scratch database and compares row counts |

Every command exits non-zero on failure (a target failed, a copy is stale, a drill failed), so a
scheduler that alerts on failed commands covers it. The jobs (`Phattarachai\DbSnapshotSyncLaravel\Jobs\…`)
run the same code; they go on `DB_SNAPSHOT_SYNC_BACKUP_QUEUE` when set, never retry, and
`BackupSnapshots` / `RunRestoreDrill` allow an hour, so put them on a queue whose worker
`--timeout` covers that instead of riding `default`.

### Configure

```php
// config/db-snapshot-sync.php
'backup' => [
    'disks' => ['spaces'],          // any disks from config/filesystems.php, one or more
    'path' => 'db',                 // prefix on each target; let the disk's root carry the app/env
    'local_days' => 14,             // snapshot:prune deletes local snapshots older than this
    'keep_min' => 3,                // ...but never below the newest 3 (local, and each target's daily/)
    'daily_days' => 14,             // {path}/daily/ keeps every snapshot this many days
    'weekly_weeks' => 8,            // {path}/weekly/ keeps the newest snapshot of each ISO week
    'stale_after_hours' => 26,      // snapshot:backup-check threshold
    'protect' => ['testing'],       // snapshot names never pruned and never uploaded
    'queue' => env('DB_SNAPSHOT_SYNC_BACKUP_QUEUE'),
],
```

On each target, `snapshot:backup`:

- **uploads** every local `.sql` / `.sql.gz` / `.sql.zst` from the last `daily_days` that is not yet under
  `{path}/daily/` with the same size. A night the box missed goes up on the next run, and a short
  copy left by an interrupted upload is replaced. Protected snapshots and anything in `api.reject`
  (`.sanitized.` intermediates) stay local. A file modified in the last 60 seconds is left for the
  next run, because spatie may still be copying it onto the disk.
- **copies** the newest snapshot of each of the last `weekly_weeks` ISO weeks to
  `{path}/weekly/{YYYY-Www}_{name}`, and replaces the current week's copy when a newer snapshot
  lands. That copy is made on the target itself when the daily copy is there (server-side on
  S3/Spaces), so nothing is uploaded twice.
- **prunes** `daily/` by the copy's age on the target and `weekly/` by the week in its name, never
  below `keep_min` on either.

Files are streamed (`readStream` / `writeStream`), never read into memory, and every object is
written with **private** visibility explicitly, whatever the disk's own default. After each
upload the target size is compared with the local one; a mismatch deletes the copy and fails that
target. One target failing does not stop the others.

### Target disks

The package needs no Flysystem adapter itself. Install the one your disk uses. Setting
`'throw' => true` makes a failure carry the adapter's own error message.

**DigitalOcean Spaces / S3** (`composer require league/flysystem-aws-s3-v3`):

```php
'spaces' => [
    'driver' => 's3',
    'key' => env('DO_SPACES_KEY'),
    'secret' => env('DO_SPACES_SECRET'),
    'region' => env('DO_SPACES_REGION'),
    'bucket' => env('DO_SPACES_BUCKET'),
    'endpoint' => env('DO_SPACES_ENDPOINT'),
    'root' => env('DO_SPACES_ROOT'),          // e.g. myapp/production
    'throw' => true,
],
```

Private visibility is sent as an object ACL. That works on Spaces and on S3 buckets with ACLs
enabled. An AWS bucket set to "bucket owner enforced" (ACLs disabled) rejects it.

**Google Drive** (`composer require masbug/flysystem-google-drive-ext`). Register a driver in a
service provider. For an unattended server, use a **service account** writing into a **Shared
Drive**: a service account has no My Drive storage quota, so uploads into a plain folder fail, and
`teamDriveId` is required. Add the service account's email to the Shared Drive as a **Content
manager**.

```php
use Google\Client;
use Google\Service\Drive;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem;
use Masbug\Flysystem\GoogleDriveAdapter;

Storage::extend('google-drive', function ($app, array $config): FilesystemAdapter {
    $client = new Client;
    $client->setAuthConfig($config['service_account']);   // path to the key JSON, or its decoded array
    $client->addScope(Drive::DRIVE);

    $adapter = new GoogleDriveAdapter(new Drive($client), $config['root'] ?? null, [
        'teamDriveId' => $config['shared_drive_id'],
    ]);

    return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
});
```

```php
'gdrive' => [
    'driver' => 'google-drive',
    'service_account' => env('GOOGLE_DRIVE_SERVICE_ACCOUNT'),   // e.g. /etc/myapp/gdrive-sa.json
    'shared_drive_id' => env('GOOGLE_DRIVE_SHARED_DRIVE_ID'),
    'root' => env('GOOGLE_DRIVE_ROOT'),                        // e.g. myapp, a folder in the Shared Drive
    'throw' => true,
],
```

Keep the callback self-contained: Laravel rebinds an `extend` closure to the `FilesystemManager`,
so `$this` inside it is the manager, not your provider, and calling a private method of the
provider from it fails.

`google/apiclient` pulls in every Google API's client (tens of thousands of files). Trim it to
Drive in the app's `composer.json`, then run `composer update google/apiclient-services`:

```json
"scripts": {
    "pre-autoload-dump": ["Google\\Task\\Composer::cleanup"]
},
"extra": {
    "google/apiclient-services": ["Drive"]
}
```

For a personal account without Workspace, an OAuth client with a refresh token works the same way
(`setClientId`, `setClientSecret`, `refreshToken` instead of `setAuthConfig`), without `teamDriveId`.

The Drive adapter throws when asked to list a folder that does not exist yet, and Drive's search can
take a moment to see a folder just created. `snapshot:backup` creates `{path}/daily` and
`{path}/weekly` on a fresh target itself, and every command reads a missing folder as empty, so a
new project needs no folders made by hand.

**NAS over sftp** (`composer require league/flysystem-sftp-v3`):

```php
'nas' => [
    'driver' => 'sftp',
    'host' => env('NAS_SFTP_HOST'),
    'port' => (int) env('NAS_SFTP_PORT', 22),
    'username' => env('NAS_SFTP_USERNAME'),
    'privateKey' => env('NAS_SFTP_PRIVATE_KEY_PATH'),
    'root' => env('NAS_SFTP_ROOT'),           // e.g. /volume1/backups/myapp-production
    'throw' => true,
],
```

A mounted volume is a plain `'driver' => 'local'` disk with `'root'` on the mount.

### Schedule

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('snapshot:create', ['--compress'])->dailyAt('04:00');
Schedule::command('snapshot:backup')->dailyAt('04:03')->withoutOverlapping();
Schedule::command('snapshot:prune')->dailyAt('04:05');
Schedule::command('snapshot:backup-check')->hourly();

// Optional: prove the off-site copy restores, e.g. monthly, off-peak.
Schedule::command('snapshot:drill')->monthlyOn(1, '05:00');
```

`snapshot:prune` replaces `snapshot:cleanup --keep=N`. Drop the old line when you add it. If the dump
takes longer than a couple of minutes, the 04:03 backup does not see it yet and it goes up a day
late. In that case chain the backup onto the create (`->then(fn () => Artisan::call('snapshot:backup'))`).

### Alerting

`snapshot:backup-check` reads the newest snapshot under `{path}/daily/` on each target and dispatches
one event per target. "Newest" here, in `snapshot:drill` and in the weekly pick goes by when the
snapshot was taken: the `Y-m-d_H-i-s` stamp spatie puts in the name (read in `app.timezone`), or the
file's modified time when the name has none. A target's modified time is only the upload time. Both implement `SnapshotBackupChecked`, so listen on that interface to get
both. (A listener on their abstract parent class never fires: Laravel matches an event's interfaces,
not its parent classes.)

An empty or failed listing is read up to twice more, 5 seconds apart and through a freshly resolved
disk, before the target counts as stale, because a transient failure on some adapters (Google Drive)
reads as an empty folder. A copy that is simply old is reported straight away.

| Event | When | Properties |
|---|---|---|
| `SnapshotBackupHealthy` | newest copy within `stale_after_hours` | `disk`, `newest`, `newestAt`, `ageSeconds`, `ageHours()`, `staleAfterHours` |
| `SnapshotBackupStale` | older than that, no copy at all, or the target could not be read | the same, with `newest`/`ageSeconds` null when there is no copy, and `error` when unreadable |

```php
use Phattarachai\DbSnapshotSyncLaravel\Events\SnapshotBackupStale;

Event::listen(SnapshotBackupStale::class, function (SnapshotBackupStale $event): void {
    Log::critical('Off-site DB backup is stale', [
        'disk' => $event->disk,
        'newest' => $event->newest,
        'age_hours' => $event->ageHours(),
        'error' => $event->error,
    ]);
});
```

### Restore drill

`snapshot:drill` downloads the newest daily copy **from a target disk** (`--disk`, default the first
in `backup.disks`), so it proves the off-site copy and not the local one. It then:

1. creates `<database>_restore_drill` on the live connection's server (`--connection`, default the
   default connection), dropping a leftover from a killed run first;
2. loads the copy into it, with psql in one transaction on PostgreSQL (see
   [How a PostgreSQL snapshot is loaded](#how-a-postgresql-snapshot-is-loaded)) and spatie's
   streamed loader on MySQL/MariaDB;
3. compares exact per-table row counts with the live database and prints the elapsed time and the
   tables that differ;
4. drops the scratch database in a `finally`, and dispatches `SnapshotDrillCompleted` with the
   `DrillResult`.

The live database is only read (row counts). Every write goes to the scratch database, and the drill
refuses to load if the scratch connection does not resolve to it. The data never leaves the box, so
the drill is meant to run on production. Row counts are taken *now*, after the snapshot, so small
deltas are normal. The drill **fails** when a live table is missing from the restore, or has rows live
but restored empty (tables in `dump.exclude_table_data` excepted), or when the download, the engine
check or the load fails.

It needs:

- **the privilege to create a database.** PostgreSQL: `ALTER ROLE <app_user> CREATEDB;`. MySQL:
  `` GRANT ALL ON `<database>_restore_drill`.* TO '<app_user>'@'<host>'; ``. Extensions the dump
  creates must be ones that user may create (trusted extensions, on PostgreSQL 13+).
- **disk space** for a second copy of the database on the DB server, plus the compressed download
  under `storage/app/db-snapshot-sync-drill/` (deleted afterwards).
- **an off-peak slot.** `count(*)` on every live table is a full scan of each.

## Testing

```bash
composer test
```

## License

MIT. See [LICENSE.md](LICENSE.md).

## ผู้พัฒนา

พัฒนาและดูแลโดย [บริษัท ภัทรชัย อาร์ทิซาน จำกัด](https://phattarachai.dev) (Phattarachai Artisan)
บริษัทที่ปรึกษาและพัฒนาเว็บ ที่เรียนรู้และแบ่งปันกับชุมชน Laravel
แพ็กเกจนี้แบ่งปันให้ชุมชนนำไปใช้และต่อยอดได้อย่างอิสระ

ดูแพ็กเกจอื่นของเราได้ที่ [phattarachai.dev/open-source](https://phattarachai.dev/open-source)
และติดต่อเราได้ที่ [phattarachai.dev](https://phattarachai.dev/#contact)
