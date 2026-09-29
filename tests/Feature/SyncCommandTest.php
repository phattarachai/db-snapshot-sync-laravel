<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

$dump = "\\restrict abc\nSET transaction_timeout = 0;\nCREATE TABLE foo (id int);\n";
$pgDump = "--\n-- PostgreSQL database dump\n--\n\\restrict abc\nCREATE TABLE foo (id int);\n";
$mysqlDump = "-- MySQL dump 10.13  Distrib 8.0.36, for Linux (x86_64)\n/*!50013 DEFINER=`root`@`%` SQL SECURITY DEFINER */\nCREATE TABLE bar (id int);\n";

/**
 * Stand in for spatie's snapshot:load (its provider isn't booted here) with the
 * same signature and the same side effect: Snapshot::load() makes the target
 * the default connection and never switches back.
 *
 * @return ArrayObject<string, mixed> the options the sync passed, once it runs
 */
function fakeSnapshotLoad(): ArrayObject
{
    $calls = new ArrayObject;

    Artisan::command('snapshot:load {name?} {--connection=} {--force} {--stream} {--drop-tables=1}', function () use ($calls): void {
        $calls['name'] = $this->argument('name');
        $calls['connection'] = $this->option('connection');
        $calls['drop-tables'] = $this->option('drop-tables');

        DB::setDefaultConnection((string) $this->option('connection'));
    });

    return $calls;
}

it('names a plain .sql download by its content, not a .gz extension', function () use ($dump): void {
    config([
        'db-snapshot-sync.sync.allowed_environments' => ['testing'],
        'db-snapshot-sync.sanitizer.pgsql.drop_prefixes' => ['\\restrict', 'SET transaction_timeout'],
    ]);
    $disk = Storage::fake('snapshots');
    Http::fake(['source.test/*' => Http::response($dump)]);

    $this->artisan('snapshot:sync', ['--no-load' => true])->assertSuccessful();

    $files = $disk->files();
    expect($files)->toHaveCount(1);
    expect($files[0])->toEndWith('.sql')->not->toEndWith('.sql.gz');
    expect($disk->get($files[0]))
        ->not->toContain('\\restrict')
        ->not->toContain('transaction_timeout')
        ->toContain('CREATE TABLE foo');
});

it('names a gzipped download .sql.gz and sanitizes it in place', function () use ($dump): void {
    config([
        'db-snapshot-sync.sync.allowed_environments' => ['testing'],
        'db-snapshot-sync.sanitizer.pgsql.drop_prefixes' => ['\\restrict', 'SET transaction_timeout'],
    ]);
    $disk = Storage::fake('snapshots');
    Http::fake(['source.test/*' => Http::response(gzencode($dump, 9))]);

    $this->artisan('snapshot:sync', ['--no-load' => true])->assertSuccessful();

    $files = $disk->files();
    expect($files)->toHaveCount(1);
    expect($files[0])->toEndWith('.sql.gz');
    expect(gzdecode($disk->get($files[0])))
        ->not->toContain('\\restrict')
        ->toContain('CREATE TABLE foo');
});

it('refuses to run outside the allowed environments', function (): void {
    // Default testbench environment is "testing", which is not allowed by default.
    $this->artisan('snapshot:sync')
        ->assertFailed()
        ->expectsOutputToContain('snapshot:sync may only run in');
});

it('errors on an unknown source', function (): void {
    config(['db-snapshot-sync.sync.allowed_environments' => ['testing']]);

    $this->artisan('snapshot:sync', ['--source' => 'bogus'])
        ->assertFailed()
        ->expectsOutputToContain('Unknown or unconfigured');
});

it('errors when the token is missing', function (): void {
    config([
        'db-snapshot-sync.sync.allowed_environments' => ['testing'],
        'db-snapshot-sync.token' => null,
    ]);

    $this->artisan('snapshot:sync')
        ->assertFailed()
        ->expectsOutputToContain('INTERNAL_API_TOKEN is not set');
});

it('sanitizes a MySQL dump with the MySQL rules via --connection while the default is pgsql', function () use ($mysqlDump): void {
    config(['db-snapshot-sync.sync.allowed_environments' => ['testing']]);
    $disk = Storage::fake('snapshots');
    Http::fake(['source.test/*' => Http::response(gzencode($mysqlDump, 9))]);

    $this->artisan('snapshot:sync', ['--no-load' => true, '--connection' => 'mysql'])
        ->assertSuccessful()
        ->expectsOutputToContain('Target connection: mysql (mysql)');

    $files = $disk->files();
    expect($files)->toHaveCount(1);
    expect($files[0])->toEndWith('.sanitized.sql.gz');
    expect(gzdecode($disk->get($files[0])))
        ->not->toContain('DEFINER=')
        ->toContain('SQL SECURITY INVOKER')
        ->toContain('CREATE TABLE bar');
})->skip(fn (): bool => ! shell_exec('command -v sed'), 'sed not available');

it('loads into the target connection and restores the caller\'s default connection', function () use ($mysqlDump): void {
    config(['db-snapshot-sync.sync.allowed_environments' => ['testing']]);
    Storage::fake('snapshots');
    Http::fake(['source.test/*' => Http::response(gzencode($mysqlDump, 9))]);
    $load = fakeSnapshotLoad();

    $this->artisan('snapshot:sync', ['--connection' => 'mysql'])->assertSuccessful();

    expect($load['connection'])->toBe('mysql');
    expect($load['name'])->toEndWith('.sanitized');
    expect(DB::getDefaultConnection())->toBe('pgsql');
})->skip(fn (): bool => ! shell_exec('command -v sed'), 'sed not available');

it('takes the target connection from an array-form source', function () use ($mysqlDump): void {
    config([
        'db-snapshot-sync.sync.allowed_environments' => ['testing'],
        'db-snapshot-sync.sources.production' => ['url' => 'https://source.test', 'connection' => 'mysql'],
    ]);
    Storage::fake('snapshots');
    Http::fake(['source.test/*' => Http::response(gzencode($mysqlDump, 9))]);
    $load = fakeSnapshotLoad();

    $this->artisan('snapshot:sync')->assertSuccessful();

    expect($load['connection'])->toBe('mysql');
    expect(DB::getDefaultConnection())->toBe('pgsql');
})->skip(fn (): bool => ! shell_exec('command -v sed'), 'sed not available');

it('lets --connection override the source connection', function () use ($pgDump): void {
    config([
        'db-snapshot-sync.sync.allowed_environments' => ['testing'],
        'db-snapshot-sync.sources.production' => ['url' => 'https://source.test', 'connection' => 'mysql'],
    ]);
    Storage::fake('snapshots');
    Http::fake(['source.test/*' => Http::response($pgDump)]);
    $load = fakeSnapshotLoad();

    $this->artisan('snapshot:sync', ['--connection' => 'pgsql'])->assertSuccessful();

    expect($load['connection'])->toBe('pgsql');
});

it('keeps a PG to PG sync on the default connection, sanitized in place', function () use ($pgDump): void {
    config([
        'db-snapshot-sync.sync.allowed_environments' => ['testing'],
        'db-snapshot-sync.sanitizer.pgsql.drop_prefixes' => ['\\restrict'],
    ]);
    $disk = Storage::fake('snapshots');
    Http::fake(['source.test/*' => Http::response(gzencode($pgDump, 9))]);
    $load = fakeSnapshotLoad();

    $this->artisan('snapshot:sync')
        ->assertSuccessful()
        ->expectsOutputToContain('Target connection: pgsql (pgsql)');

    expect($load['connection'])->toBe('pgsql');
    expect($load['drop-tables'])->toBeTrue();
    expect(DB::getDefaultConnection())->toBe('pgsql');

    $files = $disk->files();
    expect($files)->toHaveCount(1);
    expect(gzdecode($disk->get($files[0])))->not->toContain('\\restrict');
});

it('refuses a MySQL dump bound for a pgsql connection without sanitizing or loading', function () use ($mysqlDump): void {
    config(['db-snapshot-sync.sync.allowed_environments' => ['testing']]);
    $disk = Storage::fake('snapshots');
    Http::fake(['source.test/*' => Http::response(gzencode($mysqlDump, 9))]);
    $load = fakeSnapshotLoad();

    $this->artisan('snapshot:sync')
        ->assertFailed()
        ->expectsOutputToContain('is a MySQL/MariaDB (mysqldump) dump, but connection [pgsql] uses the pgsql driver');

    expect($load->count())->toBe(0);
    expect($disk->files())->toHaveCount(1);
    expect(gzdecode($disk->get($disk->files()[0])))->toContain('DEFINER=');
});

it('refuses a pg_dump bound for a mysql connection', function () use ($pgDump): void {
    config(['db-snapshot-sync.sync.allowed_environments' => ['testing']]);
    Storage::fake('snapshots');
    Http::fake(['source.test/*' => Http::response($pgDump)]);
    $load = fakeSnapshotLoad();

    $this->artisan('snapshot:sync', ['--connection' => 'mysql'])
        ->assertFailed()
        ->expectsOutputToContain('is a PostgreSQL (pg_dump) dump, but connection [mysql] uses the mysql driver');

    expect($load->count())->toBe(0);
});

it('errors on an unconfigured connection before downloading', function (): void {
    config(['db-snapshot-sync.sync.allowed_environments' => ['testing']]);
    Http::fake();

    $this->artisan('snapshot:sync', ['--connection' => 'nope'])
        ->assertFailed()
        ->expectsOutputToContain('Database connection [nope] is not configured.');

    Http::assertNothingSent();
});

it('keeps a path on the source base URL', function () use ($pgDump): void {
    config([
        'db-snapshot-sync.sync.allowed_environments' => ['testing'],
        'db-snapshot-sync.sources.production' => ['url' => 'https://source.test/api', 'connection' => 'pgsql'],
    ]);
    Storage::fake('snapshots');
    Http::fake(['source.test/*' => Http::response($pgDump)]);

    $this->artisan('snapshot:sync', ['--no-load' => true])->assertSuccessful();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://source.test/api/internal/snapshots/latest');
});
