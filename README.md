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
  → snapshot:load --drop-tables --force --stream
```

Supports **PostgreSQL** and **MySQL/MariaDB**.

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

## Configuration

`config/db-snapshot-sync.php` covers the disk name, token, source URLs, `snapshot:load` flags,
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

## Testing

```bash
composer test
```

## License

MIT. See [LICENSE.md](LICENSE.md).
