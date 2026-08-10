<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Route;

it('registers the package commands', function (string $command): void {
    expect(array_keys($this->app[Kernel::class]->all()))->toContain($command);
})->with(['snapshot:sed', 'snapshot:sync', 'db-snapshot-sync:install']);

it('merges the package config', function (): void {
    expect(config('db-snapshot-sync.sanitizer.pgsql.drop_prefixes'))->toContain('\\restrict');
});

it('registers the internal routes when the api is enabled', function (): void {
    expect(Route::has('db-snapshot-sync.snapshots.index'))->toBeTrue()
        ->and(Route::has('db-snapshot-sync.snapshots.latest'))->toBeTrue();
});
