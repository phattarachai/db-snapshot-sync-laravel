# Changelog

All notable changes to `phattarachai/db-snapshot-sync-laravel` are documented here.

Release notes are drafted automatically from merged pull requests and published on the
[Releases page](https://github.com/phattarachai/db-snapshot-sync-laravel/releases) — that page is the authoritative log.

This file records anything released before that automation landed.

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

