<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

$dump = "\\restrict abc\nSET transaction_timeout = 0;\nCREATE TABLE foo (id int);\n";

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
