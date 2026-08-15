<?php

declare(strict_types=1);

use Phattarachai\DbSnapshotSyncLaravel\Support\DumpOptions;

it('appends exclude-table-data flags after existing options', function (): void {
    $result = DumpOptions::withExcludedTableData('--no-owner --no-privileges', ['cache', 'jobs']);

    expect($result)->toBe('--no-owner --no-privileges --exclude-table-data=cache --exclude-table-data=jobs');
});

it('handles empty existing options', function (): void {
    expect(DumpOptions::withExcludedTableData('', ['sessions']))
        ->toBe('--exclude-table-data=sessions');
});

it('returns the existing options unchanged when no tables are given', function (): void {
    expect(DumpOptions::withExcludedTableData('--no-owner', []))->toBe('--no-owner');
});

it('skips empty table names', function (): void {
    expect(DumpOptions::withExcludedTableData('', ['cache', '', 'jobs']))
        ->toBe('--exclude-table-data=cache --exclude-table-data=jobs');
});

it('appends rows-per-insert after existing options', function (): void {
    expect(DumpOptions::withRowsPerInsert('--no-owner', 1000))
        ->toBe('--no-owner --rows-per-insert=1000');
});

it('leaves the dump unchanged for a null or non-positive rows-per-insert', function (): void {
    expect(DumpOptions::withRowsPerInsert('--no-owner', null))->toBe('--no-owner');
    expect(DumpOptions::withRowsPerInsert('--no-owner', 0))->toBe('--no-owner');
    expect(DumpOptions::withRowsPerInsert('--no-owner', -5))->toBe('--no-owner');
});
