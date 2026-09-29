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

it('builds exclude-table-data and rows-per-insert for pgsql', function (): void {
    expect(DumpOptions::forSync('pgsql', '--no-owner', ['cache', 'jobs'], 1000))
        ->toBe('--no-owner --exclude-table-data=cache --exclude-table-data=jobs --rows-per-insert=1000');
});

it('adds no pg_dump-only flags for mysql or mariadb', function (string $driver): void {
    expect(DumpOptions::forSync($driver, '--column-statistics=0', ['cache', 'jobs'], 1000))->toBeNull();
    expect(DumpOptions::forSync($driver, '', ['cache'], 1000))->toBeNull();
})->with(['mysql', 'mariadb', 'sqlite']);

it('returns null for pgsql when there is nothing to add', function (): void {
    expect(DumpOptions::forSync('pgsql', '', [], null))->toBeNull();
    expect(DumpOptions::forSync('pgsql', '--no-owner', ['', ''], 0))->toBeNull();
});

it('appends database-qualified ignore-table flags for mysqldump', function (): void {
    expect(DumpOptions::withIgnoredTables('--column-statistics=0', 'app', ['cache', '', 'jobs']))
        ->toBe('--column-statistics=0 --ignore-table=app.cache --ignore-table=app.jobs');
    expect(DumpOptions::withIgnoredTables('', 'app', ['sessions']))->toBe('--ignore-table=app.sessions');
});
