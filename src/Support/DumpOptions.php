<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Support;

final class DumpOptions
{
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
