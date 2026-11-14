<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Phattarachai\DbSnapshotSyncLaravel\Support\SnapshotRestorer;

$pgDump = "--\n-- PostgreSQL database dump\n--\nCREATE TABLE foo (id int);\n";
$mysqlDump = "-- MySQL dump 10.13  Distrib 8.4.0, for Linux (x86_64)\nCREATE TABLE bar (id int);\n";

/**
 * @return ArrayObject<int, array{path: string, connection: string, drop: bool, role: ?string}>
 */
function fakeRestorer(): ArrayObject
{
    $calls = new ArrayObject;

    app()->instance(SnapshotRestorer::class, new class($calls) extends SnapshotRestorer
    {
        /** @param ArrayObject<int, array<string, mixed>> $calls */
        public function __construct(private readonly ArrayObject $calls) {}

        public function restore(string $path, string $connection, bool $dropTables = true, ?string $role = null): void
        {
            $this->calls[] = ['path' => basename($path), 'connection' => $connection, 'drop' => $dropTables, 'role' => $role];
        }
    });

    return $calls;
}

beforeEach(function () use ($pgDump): void {
    $this->disk = Storage::fake('snapshots');
    $this->disk->put('2026-11-13_04-00-01.sql.gz', gzencode($pgDump, 9));
    $this->disk->put('2026-11-14_04-00-01.sql.gz', gzencode($pgDump, 9));
    $this->disk->put('testing.sql', $pgDump);
    $this->calls = fakeRestorer();
});

it('restores a snapshot named by its stem into the default connection', function (): void {
    $this->artisan('snapshot:restore', ['name' => '2026-11-13_04-00-01', '--force' => true])
        ->expectsOutputToContain('Restored in')
        ->assertSuccessful();

    expect($this->calls->getArrayCopy())->toBe([['path' => '2026-11-13_04-00-01.sql.gz', 'connection' => 'pgsql', 'drop' => true, 'role' => null]]);
});

it('takes the newest snapshot by the time in its name with --latest, never the committed baseline', function (): void {
    touch($this->disk->path('2026-11-14_04-00-01.sql.gz'), now()->subDay()->getTimestamp());

    $this->artisan('snapshot:restore', ['--latest' => true, '--force' => true, '--role' => 'app_owner', '--keep-tables' => true])->assertSuccessful();

    expect($this->calls[0])->toBe(['path' => '2026-11-14_04-00-01.sql.gz', 'connection' => 'pgsql', 'drop' => false, 'role' => 'app_owner']);
});

it('asks first, and restores nothing on a no', function (): void {
    $this->artisan('snapshot:restore', ['--latest' => true])
        ->expectsConfirmation('Replace every table in [pgsql] ('.config('database.connections.pgsql.database').') with 2026-11-14_04-00-01.sql.gz?', 'no')
        ->assertFailed();

    expect($this->calls)->toHaveCount(0);
});

it('refuses a protected environment unless it is allowed on the box and --force is passed', function (array $allowed, bool $force, bool $restores): void {
    app()->detectEnvironment(fn (): string => 'production');
    config(['db-snapshot-sync.restore.allowed_environments' => $allowed]);

    $run = $this->artisan('snapshot:restore', ['--latest' => true, '--force' => $force]);
    $restores ? $run->assertSuccessful() : $run->expectsOutputToContain('[production] is protected')->assertFailed();
    $run->run();

    expect($this->calls)->toHaveCount($restores ? 1 : 0);
})->with([
    'not allowed' => [[], true, false],
    'allowed, no --force' => [['production'], false, false],
    'allowed and --force' => [['production'], true, true],
]);

it('refuses a dump of the other engine', function () use ($mysqlDump): void {
    $this->disk->put('2026-11-15_04-00-01.sql.gz', gzencode($mysqlDump, 9));

    $this->artisan('snapshot:restore', ['name' => '2026-11-15_04-00-01.sql.gz', '--force' => true])
        ->expectsOutputToContain('is a MySQL/MariaDB (mysqldump) dump, but connection [pgsql] uses the pgsql driver')
        ->assertFailed();

    expect($this->calls)->toHaveCount(0);
});

it('names an unknown snapshot and lists the newest', function (): void {
    $this->artisan('snapshot:restore', ['name' => 'nope', '--force' => true])
        ->expectsOutputToContain('No snapshot named [nope]')
        ->expectsOutputToContain('Newest: 2026-11-14_04-00-01.sql.gz, 2026-11-13_04-00-01.sql.gz')
        ->assertFailed();
});
