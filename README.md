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

- **`snapshot:sed {name?} {--latest}`** — sanitize a snapshot on the disk so it re-imports
  cleanly. Postgres mutates in place; MySQL writes a separate `.sanitized.sql.gz`.
- **`snapshot:sync {--fresh} {--source=production} {--no-load} {--keep-raw}`** — pull the latest
  snapshot from a source, sanitize, and load into the local DB. Runs only in the environments
  listed in `db-snapshot-sync.sync.allowed_environments`.

Start with a dry run once the source URLs are set:

```bash
php artisan snapshot:sync --no-load     # download + sanitize, no DB clobber
php artisan snapshot:sync               # full sync from production
```

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
