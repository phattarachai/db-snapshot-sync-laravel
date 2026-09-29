<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->disk = Storage::fake('snapshots');
});

it('sanitizes a snapshot named on the command line', function (): void {
    $this->disk->put('snap.sql', "\\restrict tok\nCREATE TABLE foo (id int);\n");

    $this->artisan('snapshot:sed', ['name' => 'snap.sql'])
        ->assertSuccessful()
        ->expectsOutputToContain('Sanitized: snap.sql');

    expect($this->disk->get('snap.sql'))->not->toContain('\\restrict');
});

it('resolves the latest snapshot with --latest', function (): void {
    $this->disk->put('old.sql', "\\restrict a\nkeep old\n");
    $this->disk->put('new.sql', "\\restrict b\nkeep new\n");

    $this->artisan('snapshot:sed', ['--latest' => true])->assertSuccessful();
});

it('ignores .sanitized. intermediates when listing', function (): void {
    $this->disk->put('snap.sanitized.sql.gz', gzencode('x', 9));

    $this->artisan('snapshot:sed')
        ->assertSuccessful()
        ->expectsOutputToContain('No .sql or .sql.gz files found');
});

it('reports an empty disk', function (): void {
    $this->artisan('snapshot:sed')
        ->assertSuccessful()
        ->expectsOutputToContain('No .sql or .sql.gz files found');
});

$mysqlDump = "-- MySQL dump 10.13  Distrib 8.0.36, for Linux (x86_64)\n/*!50013 DEFINER=`root`@`%` SQL SECURITY DEFINER */\nCREATE TABLE bar (id int);\n";
$pgDump = "--\n-- PostgreSQL database dump\n--\n\\restrict abc\nCREATE TABLE foo (id int);\n";

it('sanitizes a MySQL dump with the MySQL rules on a pgsql-default app, by its header', function () use ($mysqlDump): void {
    $this->disk->put('snap.sql.gz', gzencode($mysqlDump, 9));

    $this->artisan('snapshot:sed', ['name' => 'snap'])
        ->assertSuccessful()
        ->expectsOutputToContain('Sanitized: snap.sql.gz → snap.sanitized.sql.gz');

    expect(gzdecode($this->disk->get('snap.sanitized.sql.gz')))->not->toContain('DEFINER=');
})->skip(fn (): bool => ! shell_exec('command -v sed'), 'sed not available');

it('sanitizes for a named connection or driver', function (array $option) use ($mysqlDump): void {
    $this->disk->put('snap.sql', $mysqlDump);

    $this->artisan('snapshot:sed', ['name' => 'snap.sql', ...$option])->assertSuccessful();

    expect(gzdecode($this->disk->get('snap.sanitized.sql.gz')))->not->toContain('DEFINER=');
})->with([
    '--connection' => [['--connection' => 'mysql']],
    '--driver' => [['--driver' => 'mysql']],
    '--driver mariadb' => [['--driver' => 'mariadb']],
])->skip(fn (): bool => ! shell_exec('command -v sed'), 'sed not available');

it('refuses a dump whose engine does not match the requested connection', function () use ($pgDump): void {
    $this->disk->put('snap.sql', $pgDump);

    $this->artisan('snapshot:sed', ['name' => 'snap.sql', '--connection' => 'mysql'])
        ->assertFailed()
        ->expectsOutputToContain('is a PostgreSQL (pg_dump) dump, but the mysql sanitizer was requested');

    expect($this->disk->get('snap.sql'))->toContain('\\restrict');
    expect($this->disk->exists('snap.sanitized.sql.gz'))->toBeFalse();
});

it('rejects --driver together with --connection', function (): void {
    $this->disk->put('snap.sql', "CREATE TABLE foo (id int);\n");

    $this->artisan('snapshot:sed', ['name' => 'snap.sql', '--driver' => 'mysql', '--connection' => 'mysql'])
        ->assertFailed()
        ->expectsOutputToContain('Pass either --driver or --connection, not both.');
});

it('rejects an unsupported driver', function (): void {
    $this->disk->put('snap.sql', "CREATE TABLE foo (id int);\n");

    $this->artisan('snapshot:sed', ['name' => 'snap.sql', '--driver' => 'sqlite'])
        ->assertFailed()
        ->expectsOutputToContain('Unsupported database driver [sqlite]');
});
