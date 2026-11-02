---
name: db-snapshot-sync
description: 'Pull a production (or UAT) database down into the local database with `php artisan snapshot:sync` from phattarachai/db-snapshot-sync-laravel: options, what it overwrites, the environment guard, source-side setup and troubleshooting. Use when asked to "pull the prod DB to local", "refresh local data", "copy production data down", "sync the database", "load a production snapshot", "ดึง DB production ลงมา", "dump production ลงมา", "ดึงข้อมูลจริงมาลง local", "อัปเดตข้อมูล local ให้ตรงกับ prod", or when snapshot:sync fails.'
---

# DB Snapshot Sync

`php artisan snapshot:sync` downloads a database snapshot from a source server (production by
default), strips the lines a local DB client rejects, and loads it into the local database. It is
the answer to "pull prod down to local"; do not hand-roll `pg_dump`/`mysqldump` over ssh.

## Pull production into local

```bash
php artisan snapshot:sync            # newest snapshot on production → sanitize → load locally
php artisan migrate                  # then apply migrations newer than the snapshot
```

- **It replaces the local database.** Loading drops every table on the target connection first
  (`load.drop-tables`), then restores. Local-only data is gone. Ask before running it if the user
  might have local work in the DB.
- **Default = the newest snapshot file already on the source's snapshots disk**, normally the
  nightly `snapshot:create` the source schedules (04:00 in the installer's snippet). Nothing is
  dumped on production, and the data is as old as that snapshot.
- **Run `php artisan migrate` afterwards.** The snapshot carries production's schema and
  `migrations` table, so migrations on the local branch that production has not run yet are
  missing until you migrate.

## Options

| Option | Effect |
|---|---|
| `--source=<key>` | A key of `sources` in `config/db-snapshot-sync.php` (default `production`, `prod` is an alias; `uat` is the other shipped key). Unknown or empty URL → `Unknown or unconfigured --source=…`. |
| `--fresh` | Asks the source to build a brand-new snapshot first (`POST /internal/snapshots`), then downloads it. **Slow** (minutes; a full dump on the production DB server). Only when the user needs data newer than the nightly. |
| `--no-load` | Download + sanitize only, the local DB is untouched. Good first run / dry run. The file stays in `storage/app/snapshots/`. |
| `--keep-raw` | Keep the raw download when the sanitizer writes a separate file (MySQL writes `.sanitized.sql.gz`; Postgres sanitizes in place, so it changes nothing there). |
| `--connection=<name>` | Local connection to sanitize for and load into. Default: the source's `connection` in config, else `database.default`. Needed when the source's engine differs from the local default (e.g. MySQL prod, pgsql local). |

PostgreSQL loads through `psql` in one transaction (needs the client installed; set
`DB_SNAPSHOT_SYNC_PSQL` if it is not on `PATH`). MySQL/MariaDB goes through spatie's
`snapshot:load --drop-tables --force --stream`. A `.sql.zst` snapshot needs the `zstd` CLI locally.

## Where it may run

`snapshot:sync` refuses environments in `sync.denied_environments` (default `production`, env
`DB_SNAPSHOT_SYNC_DENIED_ENVIRONMENTS`). A non-empty `sync.allowed_environments` (env
`DB_SNAPSHOT_SYNC_ALLOWED_ENVIRONMENTS`) decides alone instead. Errors: `snapshot:sync may not run
in: …` / `snapshot:sync may only run in: …`. A config published by v1.3 or earlier hard-codes
`allowed_environments => ['local', 'development']`. Never bypass the guard to sync on production.

## Setup

Consumer (local) side, once per project:

```bash
php artisan db-snapshot-sync:install   # --dry-run to preview
```

It publishes `config/db-snapshot-sync.php`, adds `INTERNAL_API_TOKEN` (random),
`DB_SNAPSHOT_SYNC_PROD_URL`, `DB_SNAPSHOT_SYNC_UAT_URL`, `DB_SNAPSHOT_SYNC_API=false`,
`DB_SNAPSHOT_SYNC_CA_BUNDLE` to `.env` if absent, and prints the snippets below. Locally, set
`DB_SNAPSHOT_SYNC_PROD_URL` to the source's base URL and `INTERNAL_API_TOKEN` to **the source's**
token.

Source (production/UAT) side needs:

- `DB_SNAPSHOT_SYNC_API=true` and the same `INTERNAL_API_TOKEN`. That registers
  `GET /internal/snapshots`, `POST /internal/snapshots`, `GET /internal/snapshots/latest`
  (bearer token, `throttle:5,1`; the group 404s in the `local` environment).
- spatie/laravel-db-snapshots writing to a `snapshots` disk (`config/db-snapshots.php` and
  `config/filesystems.php`), and a scheduled `snapshot:create` (plus cleanup) so `latest` has
  something to serve:

  ```php
  $schedule->command('snapshot:create')->dailyAt('04:00');
  $schedule->command('snapshot:cleanup --keep=36')->dailyAt('04:05');
  ```

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `INTERNAL_API_TOKEN is not set in your local .env.` | Put the source's token in the local `.env`. |
| HTTP 401 | Local token differs from the source's `INTERNAL_API_TOKEN`. |
| HTTP 404 on `/internal/snapshots/latest` | `DB_SNAPSHOT_SYNC_API` is not `true` on the source, the source runs with `APP_ENV=local`, the URL misses a path prefix (`https://example.com/api`), or the source has no snapshot yet (`No snapshot available.`: run or schedule `snapshot:create` there). |
| HTTP 429 | `throttle:5,1` on the API. Wait a minute. |
| `cURL error 60: unable to get local issuer certificate` | The source omits an intermediate cert. Set `DB_SNAPSHOT_SYNC_CA_BUNDLE` to that intermediate's PEM. |
| `… is a MySQL dump, but connection [x] uses the pgsql driver` | Engine mismatch; nothing was loaded. Pass `--connection=<matching connection>` or set the source's `connection` in config. |
| `--fresh` times out or the source returns 5xx | The dump runs inside the HTTP request, so a proxy or php-fpm request timeout can cut it. Drop `--fresh` and sync the nightly, or check the source's log. |
| Load fails on a new dump-tool line (`\restrict`, `DEFINER=`, …) | Add a rule under `sanitizer` in `config/db-snapshot-sync.php`; re-sanitize a file on disk with `php artisan snapshot:sed --latest`. |
| App errors on missing columns/tables after a sync | Run `php artisan migrate`. |

The package's README has the full reference (cross-engine sync, zstd, off-site backup with
`snapshot:backup`/`prune`/`backup-check`/`drill`).
