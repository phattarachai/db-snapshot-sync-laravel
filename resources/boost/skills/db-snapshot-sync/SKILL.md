---
name: db-snapshot-sync
description: 'Pull a production (or UAT) database down into the local database with `php artisan snapshot:sync` from phattarachai/db-snapshot-sync-laravel, over ssh, and restore a large or complex snapshot safely with `snapshot:restore` (spatie''s `snapshot:load` stays the default for small dumps such as a test DB): options, what they overwrite, the environment guards, ssh source setup, moving off the deprecated HTTP API, and troubleshooting. Use when asked to "pull the prod DB to local", "restore a snapshot", "roll back the database", "refresh local data", "copy production data down", "sync the database", "load a production snapshot", "ดึง DB production ลงมา", "dump production ลงมา", "ดึงข้อมูลจริงมาลง local", "อัปเดตข้อมูล local ให้ตรงกับ prod", when setting up snapshot:sync or snapshot:export, or when snapshot:sync fails.'
---

# DB Snapshot Sync

`php artisan snapshot:sync` logs in to a source box over ssh (production by default), runs
`php artisan snapshot:export` in the app there, streams the snapshot back, checks its size and
sha256, strips the lines a local DB client rejects, and loads it into the local database. It is
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
  nightly `snapshot:create` the source schedules. Nothing is dumped on production, and the data is
  as old as that snapshot.
- **Run `php artisan migrate` afterwards.** The snapshot carries production's schema and
  `migrations` table, so migrations on the local branch that production has not run yet are
  missing until you migrate.
- **It needs the user's own ssh login to the box.** That login is the only credential: there is no
  token. If `ssh <alias>` doesn't work without a prompt, the sync can't either.

## Options

| Option | Effect |
|---|---|
| `--source=<key>` | A key of `sources` in `config/db-snapshot-sync.php` (default `production`, `prod` is an alias; `uat` is the other shipped key). Unknown or empty → `Unknown or unconfigured --source=…`. |
| `--fresh` | The box builds a brand-new snapshot (`snapshot:export --fresh`), streams it, then deletes it. **Slow** (a full dump on the production DB server). Only when the user needs data newer than the nightly. |
| `--no-load` | Download + sanitize only, the local DB is untouched. Good first run / dry run. The file stays in `storage/app/snapshots/`. |
| `--keep-raw` | Keep the raw download when the sanitizer writes a separate file (MySQL writes `.sanitized.sql.gz`; Postgres sanitizes in place). |
| `--connection=<name>` | Local connection to sanitize for and load into. Default: the source's `connection` in config, else `database.default`. Needed when the source's engine differs from the local default (e.g. MySQL prod, pgsql local). |

The load is the same as `snapshot:restore` below: PostgreSQL through `psql` in one transaction,
MySQL/MariaDB through the `mysql` client (set `DB_SNAPSHOT_SYNC_PSQL` / `DB_SNAPSHOT_SYNC_MYSQL` if
the client is not on `PATH`). A `.sql.zst` snapshot needs the `zstd` CLI locally.

## Restore a snapshot that is already on the disk

```bash
php artisan snapshot:restore --latest                 # newest by the time in its name; asks first
php artisan snapshot:restore 2026-11-14_04-00-01 --force
```

**spatie's `snapshot:load` is the default; switch to `snapshot:restore` when a dump gets large or
complex.** Keep using `snapshot:load` where it already works, such as priming a test database from a
small committed dump (`php artisan snapshot:load testing --env=testing --force` in `composer test` or
CI); don't rewrite those. Use `snapshot:restore` for a production or UAT-sized dump (what
`snapshot:sync` pulls), a dump big enough that reading it whole into PHP memory is a risk, or a
database you can't afford to leave empty if the load fails. (On PostgreSQL both drop every object
of the schema first, collations, types and functions included, so a committed dump reloads into the
same database; a hand-added `IF NOT EXISTS` in it isn't needed.) That's because `snapshot:load` (default
mode) drops the old objects first, then reads the whole dump into one PHP string: a large dump runs out of
memory (or the error tracker's SQL breadcrumb does) and leaves the database empty. `snapshot:restore`
streams instead:

- PostgreSQL: streamed into `psql` in one transaction. The old objects (tables, views, sequences,
  types, domains, functions, collations; never extensions) are dropped inside it, so a failure or a truncated file
  leaves the database as it was. `COMMENT ON EXTENSION` lines are skipped. `--role=<owner>` runs
  `SET ROLE` first so restored objects belong to the app's role. A `pg_dump -Fc` archive is refused
  (that needs pg_restore).
- MySQL/MariaDB: streamed into `mysql`; tables are replaced one by one (DDL commits, so a failure
  part-way is partial but never empty), and tables the dump lacks are dropped afterwards.
- `--connection=`, `--keep-tables` (don't drop first), `--force` (no prompt).
- **Protected environments** (`restore.denied_environments`, default production) refuse unless the
  box lists itself in `DB_SNAPSHOT_SYNC_RESTORE_ALLOWED_ENVIRONMENTS` **and** `--force` is passed.
  Don't set that variable to get past the guard unless the user asked for a production restore.
- **Prohibitable, like `migrate:fresh`.** Apps prohibit both destructive commands beside
  `DB::prohibitDestructiveCommands(...)` with `DbSnapshotSync::prohibitDestructiveCommands($this->app->isProduction())`
  (or `RestoreCommand::prohibit(...)` / `SyncCommand::prohibit(...)`). "This command is prohibited from
  running in this environment." means the app did that on purpose: don't work around it.
- Run `php artisan migrate` afterwards when the code is newer than the snapshot.

## Where it may run

`snapshot:sync` refuses environments in `sync.denied_environments` (default `production`, env
`DB_SNAPSHOT_SYNC_DENIED_ENVIRONMENTS`). A non-empty `sync.allowed_environments` (env
`DB_SNAPSHOT_SYNC_ALLOWED_ENVIRONMENTS`) decides alone instead. Errors: `snapshot:sync may not run
in: …` / `snapshot:sync may only run in: …`. A config published by v1.3 or earlier hard-codes
`allowed_environments => ['local', 'development']`. Never bypass the guard to sync on production.

## Sources

Each source names the box the way the user already logs in:

```php
'sources' => [
    'production' => [
        'ssh' => env('DB_SNAPSHOT_SYNC_PROD_SSH', 'prod-box'),            // ~/.ssh/config Host alias
        'path' => env('DB_SNAPSHOT_SYNC_PROD_PATH', '/var/www/app/current'),
        'run_as' => env('DB_SNAPSHOT_SYNC_PROD_RUN_AS', 'www-data'),      // null when ssh user = app user
        // 'php' => 'php8.5', 'ssh_options' => ['ProxyJump' => 'bastion'], 'connection' => 'mysql',
    ],
],
```

- None of it is secret: commit hosts and paths in the project's config. If the project has an
  `.rh.json`, its targets already hold the same `host`, `path` and `run_as`.
- Find `run_as` with `ssh <alias> 'stat -L -c %U <path>/storage'`; set it when it isn't the ssh user.
  It runs artisan through `sudo -n -u <run_as>`; check with `ssh <alias> 'sudo -n -u www-data true'`.
- A user without full sudo gets a sudoers rule for one root-owned wrapper and the source sets
  `'wrapper' => '/usr/local/bin/snapshot-export'` instead of `path` (README "SSH sources").
- `snapshot:sync` runs `ssh -o BatchMode=yes <alias> "cd <path> && sudo -n -u <run_as> env HOME=/tmp php -d display_errors=stderr artisan snapshot:export …"`.

**The box** needs this package deployed (1.6+), spatie/laravel-db-snapshots on a `snapshots` disk,
and a scheduled `snapshot:create` (plus cleanup). No route, no env flag, no token. Keep the
snapshots directory unreadable to other users (`2750` dir, `0640` files).

```php
$schedule->command('snapshot:create')->dailyAt('04:00');
$schedule->command('snapshot:cleanup --keep=36')->dailyAt('04:05');
```

`snapshot:export {--fresh} {--disk=} {--pattern=}` is the box-side command: snapshot bytes on
stdout, progress and a closing `[snapshot-export] {"name","size","sha256"}` line on stderr. It
refuses a terminal; to grab a file by hand, redirect it (`… snapshot:export > x.sql.gz`).

## Moving off the HTTP API (deprecated, removed in 2.0)

A source that is a plain URL, or has a `url` and no `ssh`, still pulls from `/internal/snapshots`
with `INTERNAL_API_TOKEN` and prints a deprecation warning. To move a project:

1. Deploy 1.6+ to the box.
2. Add `ssh`, `path` (and `run_as`) to each source; an `ssh` host wins over a `url`.
3. `php artisan snapshot:sync --no-load` to check.
4. Remove the API: on the box unset `DB_SNAPSHOT_SYNC_API` and `INTERNAL_API_TOKEN` (encrypted env
   files included); locally drop `INTERNAL_API_TOKEN`, `DB_SNAPSHOT_SYNC_*_URL`,
   `DB_SNAPSHOT_SYNC_CA_BUNDLE`, and the `url` keys from the config. Any app route that reused
   `EnsureInternalToken` moves to ssh too.

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `ssh could not log in to [alias]` | `ssh <alias>` must work with no prompt (key + agent; `BatchMode=yes`). Missing `Host` block, key not enrolled on the box, or the VPN/IAP proxy is down. |
| `cannot run artisan as [www-data] without a password` | No passwordless sudo to `run_as`. Grant it, use a `wrapper` + sudoers rule, or drop `run_as` if the ssh user is the app user. |
| `… runs a version … without snapshot:export. Deploy ^1.6 there first.` | The box still has an older package. Deploy, then pull again. |
| `[path] does not exist on [alias]` | Wrong `path`; in a release layout it is `…/current`. |
| `No snapshot on disk [snapshots]` | The box has no snapshot yet. Run or schedule `snapshot:create` there, or pull with `--fresh`. |
| `The download of … is incomplete or corrupt` | The connection dropped mid-stream; the file was deleted and nothing loaded. Run it again. |
| `Unknown or unconfigured --source=…` | The source has neither `ssh` nor `url`. Set its host and path. |
| `INTERNAL_API_TOKEN is not set` / HTTP 401, 404, 429 | An HTTP source. Move it to ssh (above). |
| `… is a MySQL dump, but connection [x] uses the pgsql driver` | Engine mismatch; nothing was loaded. Pass `--connection=<matching connection>` or set the source's `connection`. |
| Load fails on a new dump-tool line (`\restrict`, `DEFINER=`, …) | Add a rule under `sanitizer` in `config/db-snapshot-sync.php`; re-sanitize a file on disk with `php artisan snapshot:sed --latest`. |
| App errors on missing columns/tables after a sync | Run `php artisan migrate`. |
| `This command is prohibited from running in this environment.` | The app prohibits it (`DbSnapshotSync::prohibitDestructiveCommands`) in this environment. By design; ask the user. |
| `[production] is protected` from `snapshot:restore` | By design. Only on the user's request: list the env in `DB_SNAPSHOT_SYNC_RESTORE_ALLOWED_ENVIRONMENTS` on that box, then `--force`. |
| `must be owner of …` during a restore | The connection's user doesn't own the existing objects. Restore as the owner, or pass `--role=<owner>` if the user is a member of it. |
| `… is a pg_dump custom-format archive` | A `-Fc` file: restore it with `pg_restore --single-transaction --no-owner`. |

The package's README has the full reference (ssh sources, safe restore, cross-engine sync, zstd, off-site backup
with `snapshot:backup`/`prune`/`backup-check`/`drill`).
