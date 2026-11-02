# DB Snapshot Sync

This project pulls its production database down to local with `php artisan snapshot:sync` (phattarachai/db-snapshot-sync-laravel). It replaces the local database, so run `php artisan migrate` afterwards for migrations newer than the snapshot. For options, setup and troubleshooting, use the `db-snapshot-sync` skill.
