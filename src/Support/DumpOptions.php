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
}
