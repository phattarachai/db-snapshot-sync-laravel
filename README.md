# DB Snapshot Sync

[![Packagist Version](https://img.shields.io/packagist/v/phattarachai/db-snapshot-sync-laravel.svg?style=flat-square)](https://packagist.org/packages/phattarachai/db-snapshot-sync-laravel)
[![Tests](https://img.shields.io/github/actions/workflow/status/phattarachai/db-snapshot-sync-laravel/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/phattarachai/db-snapshot-sync-laravel/actions/workflows/run-tests.yml)
[![PHPStan](https://img.shields.io/github/actions/workflow/status/phattarachai/db-snapshot-sync-laravel/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/phattarachai/db-snapshot-sync-laravel/actions/workflows/phpstan.yml)
[![License](https://img.shields.io/packagist/l/phattarachai/db-snapshot-sync-laravel.svg?style=flat-square)](LICENSE.md)

Rebuild a Laravel developer's local database from production in one command. Extends
[`spatie/laravel-db-snapshots`](https://github.com/spatie/laravel-db-snapshots) with the three
pieces every project otherwise reimplements: a driver-aware dump **sanitizer**, a **sync**
orchestrator, and the token-protected **internal API** the source server exposes.

```
snapshot:sync --fresh
  → trigger a fresh dump on production (internal API)
  → download the .sql.gz
  → snapshot:sed  (strip directives that reject on a local client)
  → load: psql, in one transaction (PostgreSQL)
          snapshot:load --drop-tables --force --stream (MySQL/MariaDB)
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

The installer publishes the config, writes the env keys (generating a token), and prints the
source-side snippets you paste into `config/db-snapshots.php`, `config/filesystems.php`,
`config/database.php` (dump exclusions) and the scheduler. It requires
`spatie/laravel-db-snapshots` and a `snapshots` filesystem disk on **both** ends.

## Commands

- **`snapshot:sed {name?} {--latest} {--connection=} {--driver=}`** — sanitize a snapshot on the
  disk so it re-imports cleanly. Postgres mutates in place; MySQL writes a separate
  `.sanitized.sql.gz`. Without `--connection`/`--driver` it uses the rules for the engine named in
  the dump's header, falling back to the default connection's driver.
- **`snapshot:sync {--fresh} {--source=production} {--connection=} {--no-load} {--keep-raw}`** —
  pull the latest snapshot from a source, sanitize, and load into the local DB. Runs only in the
  environments listed in `db-snapshot-sync.sync.allowed_environments`.

- **`snapshot:backup`**, **`snapshot:prune {--days=} {--dry-run}`**, **`snapshot:backup-check`**,
  **`snapshot:drill {--disk=} {--connection=}`**: off-site backup, local retention, staleness
  check and restore drill. See [Off-site backup & retention](#off-site-backup--retention).

Start with a dry run once the source URLs are set:

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
        'url' => env('DB_SNAPSHOT_SYNC_PROD_URL'),
        'connection' => 'mysql',
    ],
    'dev' => env('DB_SNAPSHOT_SYNC_DEV_URL'),   // PG → PG, default connection
],
```

A source URL is a base URL, so it may carry a path: a source that serves the API under `/api`
(`/api/internal/snapshots`) is `'url' => 'https://example.com/api'`.

`--connection` wins over the source's `connection`, which wins over `database.default`. The
connection's driver picks the sanitizer (so a MySQL dump gets its `DEFINER=` lines stripped even
when the default is `pgsql`), and the name is passed to `snapshot:load --connection`.

**Engine guard.** Before sanitizing, the sync reads the dump's header (`-- MySQL dump` /
`-- MariaDB dump` vs `-- PostgreSQL database dump`, gzipped or not). If it names a different engine
from the target connection's driver, the sync stops with an error — nothing is sanitized or
loaded, and the download stays on the disk. Without this, `snapshot:load --drop-tables` would
drop every table on the wrong database first and only then fail on the foreign SQL. A dump whose
header names neither engine is let through. `snapshot:sed --connection`/`--driver` applies the
same check.

**Loading into a non-default connection in-process.** spatie's `Snapshot::load($connection)` calls
`DB::setDefaultConnection($connection)` and never restores it, and a `pg_dump` leaves
`search_path = ''` on the connection it ran through. `snapshot:sync` puts the caller's default
connection back and purges the target connection after the load, so a command that calls
`snapshot:sync` and then keeps querying sees its own default and a fresh session. If you call
spatie's `snapshot:load --connection=…` directly, do the same:

```php
$default = DB::getDefaultConnection();

try {
    Artisan::call('snapshot:load', ['name' => $name, '--connection' => 'mysql', '--stream' => true, '--force' => true]);
} finally {
    DB::setDefaultConnection($default);
    DB::purge('mysql');
}
```

The source side is unchanged: the internal API dumps the source app's own default connection.

## The source-side API

Set `DB_SNAPSHOT_SYNC_API=true` (and the shared `INTERNAL_API_TOKEN`) on production/UAT to expose:

| Method | Route | Purpose |
|---|---|---|
| GET | `/internal/snapshots` | list servable snapshots |
| POST | `/internal/snapshots` | create a fresh one synchronously (`--fresh`) |
| GET | `/internal/snapshots/latest` | stream the newest download |

`EnsureInternalToken` 404s the whole group in `local` and checks a `hash_equals` bearer token
otherwise; the routes carry `throttle:5,1`. The consumer sends the matching token from its `.env`.

## How a PostgreSQL snapshot is loaded

A pgsql target is loaded with **`psql`**, not spatie's `snapshot:load`. spatie splits the dump into
statements in PHP and treats a backslash inside a `'…'` literal as an escape, but `pg_dump` writes
with `standard_conforming_strings = on`, where a backslash is literal. A value such as `I\'ve`
(dumped as `'I\''ve'`) throws its quote tracking off. Everything after it folds into one trailing
statement that never ends in `;`, and spatie discards that without an error. The load then reports
success with every later table empty.

`psql` is pg_dump's own parser. `snapshot:sync` streams the dump into it with `ON_ERROR_STOP=1`,
inside one transaction that holds both the table drop and the restore. `COMMIT` is sent only after
the whole file has been read and it ends with pg_dump's `-- PostgreSQL database dump complete`
trailer. A failed statement, a read error or a truncated download therefore exits non-zero and rolls
back to the database as it was. Connection settings reach psql as `PG*` environment variables, so the
password never appears in the process list.

This needs the PostgreSQL client on the machine running the sync. Set `DB_SNAPSHOT_SYNC_PSQL` when
`psql` is not on `PATH` (e.g. `/opt/homebrew/opt/libpq/bin/psql`). Of the `load` flags, only
`drop-tables` applies to a pgsql load. MySQL/MariaDB still goes through `snapshot:load`.

## Configuration

`config/db-snapshot-sync.php` covers the disk name, token, source URLs, load flags, the `psql` binary,
the per-driver sanitizer rules (add a prefix/`sed` expression when a new dump quirk appears), and
the API's reject-list (never serve a schema-only baseline or a `.sanitized.` intermediate).

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
snapshot as a second gzip member. `gunzip`, `zcat` and spatie's `snapshot:load --stream` read the
whole file; PHP's `gzdecode()` (spatie's non-streamed load) stops at the first member, so load a raw
MySQL sync snapshot with `--stream`. `snapshot:sync` already does both: the MySQL sanitizer
recompresses the file into a single member.

`dump.rows_per_insert` is PostgreSQL-only (it becomes `--rows-per-insert`); mysqldump's default
`--extended-insert` already batches rows.

### Sources with an incomplete TLS chain

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

- **uploads** every local `.sql` / `.sql.gz` from the last `daily_days` that is not yet under
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

**Google Drive** (e.g. `composer require masbug/flysystem-google-drive-ext`). Register a driver in a
service provider, as that adapter's README shows:

```php
Storage::extend('google', function ($app, array $config) {
    $client = new \Google\Client;
    $client->setClientId($config['clientId']);
    $client->setClientSecret($config['clientSecret']);
    $client->refreshToken($config['refreshToken']);

    $adapter = new \Masbug\Flysystem\GoogleDriveAdapter(new \Google\Service\Drive($client), $config['folder']);

    return new \Illuminate\Filesystem\FilesystemAdapter(new \League\Flysystem\Filesystem($adapter), $adapter, $config);
});
```

```php
'gdrive' => [
    'driver' => 'google',
    'clientId' => env('GOOGLE_DRIVE_CLIENT_ID'),
    'clientSecret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
    'refreshToken' => env('GOOGLE_DRIVE_REFRESH_TOKEN'),
    'folder' => env('GOOGLE_DRIVE_FOLDER'),   // e.g. backups/myapp-production
    'throw' => true,
],
```

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

`snapshot:backup-check` reads the newest object under `{path}/daily/` on each target and dispatches
one event per target. Both extend `SnapshotBackupStatus`, so you can listen to that for both:

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
