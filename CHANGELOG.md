# Changelog

All notable changes to `phattarachai/db-snapshot-sync-laravel` are documented here.

Release notes are drafted automatically from merged pull requests and published on the
[Releases page](https://github.com/phattarachai/db-snapshot-sync-laravel/releases) — that page is the authoritative log.

This file records anything released before that automation landed.

## v1.4.0

### Changed

- `snapshot:sync` runs by default in every environment except `production` (new
  `sync.denied_environments`, env `DB_SNAPSHOT_SYNC_DENIED_ENVIRONMENTS`), instead of only `local`
  and `development`. `sync.allowed_environments` (env `DB_SNAPSHOT_SYNC_ALLOWED_ENVIRONMENTS`) now
  defaults to empty; when a project sets it, it still decides alone. A config published by 1.3 or
  earlier keeps its `['local', 'development']` allow-list, so its behaviour does not change until
  the `sync` block is replaced.

## v1.3.0

### Added

- zstd snapshots (`.sql.zst`). `DB_SNAPSHOT_SYNC_COMPRESSION=zstd` makes `snapshot:create --compress`
  (the schedule and the API's `--fresh` snapshot) write zstd instead of gzip, through the `zstd` CLI.
  Every reader sniffs the format by magic bytes: the API lists and serves `.sql.zst`, `snapshot:sync`
  names a zstd download `.sql.zst`, both sanitizers keep it zstd, psql loads it, and backup, prune
  and drill handle it. spatie's `SnapshotFactory`, `SnapshotRepository` and `Snapshot` are extended,
  so `snapshot:load`, `:list`, `:delete` and `:cleanup` see `.sql.zst` too. gzip stays the default.

### Fixed

- The MySQL sanitizer's pipeline runs with `pipefail`, so a corrupt or truncated input fails instead
  of leaving a short `.sanitized.` copy.

## v1.2.2

### Fixed

- The newest snapshot (`snapshot:backup-check`, `snapshot:drill`, the weekly copy, `keep_min`) is
  chosen by when it was taken (the `Y-m-d_H-i-s` stamp in spatie's name, else the modified time),
  not by the target's modified time, which is the upload time. A first catch-up run had uploaded
  the oldest snapshot last, so the check reported it as newest and the drill restored it.
  `snapshot:backup` also uploads a catch-up batch oldest first.

## v1.2.1

### Fixed

- Off-site backup to Google Drive (`masbug/flysystem-google-drive-ext`) on a fresh target:
  `snapshot:backup` creates `{path}/daily` and `{path}/weekly` before listing them, and every
  command reads a folder that does not exist (or that Drive's search does not see yet) as empty, so
  `snapshot:backup-check` reports a target with no `daily/` as holding no copy instead of
  unreadable. An unreachable disk still fails as before.

### Docs

- README: Google Drive with a service account and a Shared Drive (`teamDriveId`, Content manager),
  `setAuthConfig` + `Drive::DRIVE`, the `extend` closure being bound to the `FilesystemManager`,
  and trimming `google/apiclient-services` to Drive.

## v1.2.0

### Added

- Off-site backup and age-based retention for the source's snapshots: `snapshot:backup` (streams
  every local snapshot to each disk in `backup.disks` under `{path}/daily/` plus the newest of each
  ISO week under `{path}/weekly/`, always with private visibility, size-verified, prunes the targets
  by age), `snapshot:prune` (local retention by age with `keep_min` and `protect`, replacing
  `snapshot:cleanup --keep`), `snapshot:backup-check` (dispatches `SnapshotBackupStale` /
  `SnapshotBackupHealthy`, both implementing `SnapshotBackupChecked`) and `snapshot:drill` (restores the newest off-site copy into
  `<database>_restore_drill`, diffs row counts with the live database, drops it, and dispatches
  `SnapshotDrillCompleted`). Each has a queued job: `BackupSnapshots`, `PruneSnapshots`,
  `CheckSnapshotBackup`, `RunRestoreDrill`.

### Fixed

- PostgreSQL snapshots load with psql, so a dump spatie's statement splitter mis-reads fails
  instead of loading partially (#6).

