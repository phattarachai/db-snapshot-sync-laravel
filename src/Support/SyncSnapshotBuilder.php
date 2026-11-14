<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Phattarachai\DbSnapshotSyncLaravel\Compression\Compression;
use RuntimeException;
use Throwable;

/**
 * Builds a fresh snapshot of the default connection for a sync (`--fresh`), with
 * the sync-only dump options on top of the connection's own.
 */
final readonly class SyncSnapshotBuilder
{
    public function __construct(private MySqlTableDataExclusion $mysql) {}

    /**
     * @return string the new file's name on the snapshots disk
     */
    public function build(): string
    {
        $connection = (string) config('database.default');
        $dump = config("database.connections.{$connection}.dump");

        $schemaOnly = $this->applySyncDumpOptions($connection);

        $name = 'sync_'.Carbon::now()->format('Y-m-d_H-i-s');

        try {
            Artisan::call('snapshot:create', ['name' => $name, '--compress' => true]);
        } finally {
            config(["database.connections.{$connection}.dump" => $dump]);
        }

        $disk = Storage::disk((string) config('db-snapshot-sync.disk'));
        $file = $name.Compression::configured()->suffix();

        if (! $disk->exists($file)) {
            throw new RuntimeException('snapshot:create finished but the expected file is missing.');
        }

        try {
            if ($schemaOnly !== []) {
                $this->mysql->appendSchema($connection, $schemaOnly, $disk->path($file));
            }
        } catch (Throwable $e) {
            // Never leave a snapshot missing those tables for a later pull to serve.
            $disk->delete($file);

            throw $e;
        }

        return $file;
    }

    /**
     * Merge the sync-only dump options — framework table-data excludes and
     * multi-row INSERT batching — into the connection's dump flags, for this
     * snapshot only (build() restores them), so a project's rollback snapshots
     * and committed baseline (which want single-row INSERTs for clean diffs) are
     * untouched.
     *
     * pgsql excludes data in one pass with pg_dump flags. mysql/mariadb ignore the
     * existing excluded tables here and get their schema appended afterwards;
     * those tables are returned for that second pass.
     *
     * @return list<string>
     */
    private function applySyncDumpOptions(string $connection): array
    {
        $tables = array_values(array_filter((array) config('db-snapshot-sync.dump.exclude_table_data', [])));
        $rowsPerInsert = config('db-snapshot-sync.dump.rows_per_insert');

        $driver = (string) config("database.connections.{$connection}.driver");
        $key = "database.connections.{$connection}.dump.addExtraOption";
        $existing = (string) config($key, '');

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $schemaOnly = $this->mysql->existing($connection, $tables);

            if ($schemaOnly !== []) {
                config([$key => DumpOptions::withIgnoredTables($existing, $this->mysql->database($connection), $schemaOnly)]);
            }

            return $schemaOnly;
        }

        $options = DumpOptions::forSync($driver, $existing, $tables, $rowsPerInsert === null ? null : (int) $rowsPerInsert);

        if ($options !== null) {
            config([$key => $options]);
        }

        return [];
    }
}
