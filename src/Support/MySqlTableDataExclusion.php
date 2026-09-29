<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Spatie\DbSnapshots\DbDumperFactory;

/**
 * mysqldump has no per-table "schema but no data" flag, and `--ignore-table`
 * drops the schema too. So the sync dump runs in two passes: the main dump
 * ignores the excluded tables entirely, then a `--no-data` dump of just those
 * tables is appended to the same file (as a second gzip member — valid gzip,
 * read whole by `gunzip`, `gzopen` and so spatie's streamed load).
 */
class MySqlTableDataExclusion
{
    /**
     * The subset of the given tables that exist as base tables in the
     * connection's database — the `--no-data` pass must not name a missing one.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    public function existing(string $connection, array $tables): array
    {
        if ($tables === []) {
            return [];
        }

        $rows = DB::connection($connection)->select(
            'select table_name as name from information_schema.tables where table_schema = ? and table_type = ? and table_name in ('
                .implode(', ', array_fill(0, count($tables), '?')).')',
            [$this->database($connection), 'BASE TABLE', ...$tables],
        );

        $found = array_map(static fn (object $row): string => (string) $row->name, $rows);

        return array_values(array_filter($tables, static fn (string $table): bool => in_array($table, $found, true)));
    }

    /**
     * The database name spatie's dumper targets, which `--ignore-table` must qualify with.
     */
    public function database(string $connection): string
    {
        return (string) (config("database.connections.{$connection}.connect_via_database")
            ?? config("database.connections.{$connection}.database"));
    }

    /**
     * Dump the given tables' schema (no rows) and append it to the snapshot file.
     *
     * @param  list<string>  $tables
     */
    public function appendSchema(string $connection, array $tables, string $path): void
    {
        $key = "database.connections.{$connection}.dump";
        $dump = config($key);

        // The connection's own table filters would clash with --tables (spatie throws
        // on include + exclude), so build this pass without them.
        config([$key => Arr::except((array) $dump, ['includeTables', 'excludeTables', 'excludeTablesData'])]);

        try {
            $dumper = DbDumperFactory::createForConnection($connection);
        } finally {
            config([$key => $dump]);
        }

        $schemaFile = (string) tempnam(sys_get_temp_dir(), 'db-snapshot-sync-');

        try {
            $dumper->includeTables($tables)->doNotDumpData()->dumpToFile($schemaFile);

            $sql = (string) file_get_contents($schemaFile);

            file_put_contents($path, str_ends_with($path, '.gz') ? gzencode($sql, 9) : $sql, FILE_APPEND);
        } finally {
            @unlink($schemaFile);
        }
    }
}
