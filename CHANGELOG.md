# Changelog

All notable changes to `phattarachai/db-snapshot-sync-laravel` are documented here.

Release notes are drafted automatically from merged pull requests and published on the
[Releases page](https://github.com/phattarachai/db-snapshot-sync-laravel/releases) — that page is the authoritative log.

This file records anything released before that automation landed.

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

