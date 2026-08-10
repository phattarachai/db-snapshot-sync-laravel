<?php

declare(strict_types=1);

use Phattarachai\DbSnapshotSyncLaravel\Sanitizers\MySqlSanitizer;
use Phattarachai\DbSnapshotSyncLaravel\Sanitizers\PostgresSanitizer;
use Phattarachai\DbSnapshotSyncLaravel\Sanitizers\SanitizerFactory;

it('resolves a Postgres sanitizer for pgsql', function (): void {
    expect(app(SanitizerFactory::class)->for('pgsql'))->toBeInstanceOf(PostgresSanitizer::class);
});

it('resolves a MySQL sanitizer for mysql and mariadb', function (string $driver): void {
    expect(app(SanitizerFactory::class)->for($driver))->toBeInstanceOf(MySqlSanitizer::class);
})->with(['mysql', 'mariadb']);

it('resolves from the default connection driver', function (): void {
    config(['database.default' => 'pgsql', 'database.connections.pgsql.driver' => 'pgsql']);

    expect(app(SanitizerFactory::class)->forDefaultConnection())->toBeInstanceOf(PostgresSanitizer::class);
});

it('throws for an unsupported driver', function (): void {
    app(SanitizerFactory::class)->for('sqlite');
})->throws(InvalidArgumentException::class, 'Unsupported database driver [sqlite]');
