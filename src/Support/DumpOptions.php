<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Support;

final class DumpOptions
{
    /**
     * Build the sync dump's extra-option string for a connection driver, or null
     * when there is nothing to add — the caller then leaves the connection's
     * `addExtraOption` untouched (spatie calls it with no argument on an empty
     * string, which throws).
     *
     * Only pg_dump understands `--exclude-table-data` and `--rows-per-insert`.
     * mysqldump has no per-table "schema but no data" flag (`--ignore-table`
     * drops the schema too), and its `--extended-insert` default already batches
     * rows, so mysql/mariadb get neither.
     *
     * @param  list<string>  $excludeTableData
     */
    public static function forSync(string $driver, string $existing, array $excludeTableData, ?int $rowsPerInsert): ?string
    {
        if ($driver !== 'pgsql') {
            return null;
        }

        $options = self::withRowsPerInsert(self::withExcludedTableData($existing, $excludeTableData), $rowsPerInsert);

        return $options === trim($existing) ? null : $options;
    }

    /**
     * Append `--exclude-table-data=<table>` flags to an existing dump option string,
     * so the schema is still dumped but the (large, disposable) data is skipped.
     *
     * @param  list<string>  $excludeTableData
     */
    public static function withExcludedTableData(string $existing, array $excludeTableData): string
    {
        $flags = array_map(
            static fn (string $table): string => '--exclude-table-data='.$table,
            array_values(array_filter($excludeTableData)),
        );

        return trim(trim($existing).' '.implode(' ', $flags));
    }

    /**
     * Add `--rows-per-insert=N` so pg_dump batches rows into multi-row INSERTs
     * instead of one statement per row. laravel-db-snapshots forces `--inserts`
     * (its PDO loader can't stream COPY), so a large table restores one round-trip
     * per row without this — orders of magnitude slower. A non-positive value
     * leaves the dump as-is.
     */
    public static function withRowsPerInsert(string $existing, ?int $rowsPerInsert): string
    {
        if ($rowsPerInsert === null || $rowsPerInsert < 1) {
            return trim($existing);
        }

        return trim(trim($existing).' --rows-per-insert='.$rowsPerInsert);
    }
}
