<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->disk = Storage::fake('snapshots');
});

it('lists servable snapshots for a valid token', function (): void {
    $this->disk->put('2026-01-01.sql.gz', gzencode('x', 9));

    $this->withToken('secret-token')
        ->getJson('/internal/snapshots')
        ->assertOk()
        ->assertJsonPath('snapshots.0.name', '2026-01-01.sql.gz');
});

it('hides rejected files from the listing', function (): void {
    config(['db-snapshot-sync.api.reject' => ['.sanitized.', 'testing']]);

    $this->disk->put('real.sql.gz', gzencode('x', 9));
    $this->disk->put('dump.sanitized.sql.gz', gzencode('x', 9));
    $this->disk->put('testing.sql', 'x');

    $response = $this->withToken('secret-token')->getJson('/internal/snapshots')->assertOk();

    $names = collect($response->json('snapshots'))->pluck('name');
    expect($names)->toContain('real.sql.gz')
        ->not->toContain('dump.sanitized.sql.gz')
        ->not->toContain('testing.sql');
});

it('rejects a missing or wrong token with 401', function (): void {
    $this->getJson('/internal/snapshots')->assertUnauthorized();
    $this->withToken('nope')->getJson('/internal/snapshots')->assertUnauthorized();
});

it('404s the whole API in a local environment', function (): void {
    $this->app['env'] = 'local';

    $this->withToken('secret-token')->getJson('/internal/snapshots')->assertNotFound();
});

it('downloads the latest snapshot', function (): void {
    $this->disk->put('older.sql.gz', gzencode('a', 9));
    $this->disk->put('newer.sql.gz', gzencode('b', 9));

    $this->withToken('secret-token')
        ->get('/internal/snapshots/latest')
        ->assertOk()
        ->assertDownload();
});

it('404s latest when no snapshot exists', function (): void {
    $this->withToken('secret-token')->getJson('/internal/snapshots/latest')->assertNotFound();
});

it('adds the pg_dump sync flags to the pgsql connection when creating a snapshot', function (): void {
    config(['database.connections.pgsql.dump.addExtraOption' => '--no-owner']);
    config(['db-snapshot-sync.dump.exclude_table_data' => ['cache']]);
    config(['db-snapshot-sync.dump.rows_per_insert' => 500]);

    $seen = fakeSnapshotCreate($this->disk, 'pgsql');

    $this->withToken('secret-token')->postJson('/internal/snapshots')->assertOk();

    expect($seen())->toBe('--no-owner --exclude-table-data=cache --rows-per-insert=500');
});

it('leaves a mysql connection\'s dump options untouched when creating a snapshot', function (): void {
    config(['database.default' => 'mysql']);
    config(['database.connections.mysql.driver' => 'mysql']);
    config(['database.connections.mysql.dump.addExtraOption' => '--column-statistics=0']);

    $seen = fakeSnapshotCreate($this->disk, 'mysql');

    $this->withToken('secret-token')->postJson('/internal/snapshots')->assertOk();

    expect($seen())->toBe('--column-statistics=0');
});

it('never writes an empty addExtraOption when there is nothing to add', function (): void {
    config(['db-snapshot-sync.dump.exclude_table_data' => []]);
    config(['db-snapshot-sync.dump.rows_per_insert' => null]);
    config(['database.connections.pgsql.dump' => ['useInserts']]);

    $seen = fakeSnapshotCreate($this->disk, 'pgsql');

    $this->withToken('secret-token')->postJson('/internal/snapshots')->assertOk();

    expect($seen())->toBe('<unset>')
        ->and(config('database.connections.pgsql.dump'))->toBe(['useInserts']);
});

/**
 * Swap snapshot:create for a stub that records the connection's addExtraOption
 * at dump time and writes the file store() expects.
 *
 * @return Closure(): string
 */
function fakeSnapshotCreate(Filesystem $disk, string $connection): Closure
{
    $seen = '<not called>';

    Artisan::command('snapshot:create {name} {--compress}', function (string $name) use ($disk, $connection, &$seen): void {
        $seen = config()->has("database.connections.{$connection}.dump.addExtraOption")
            ? (string) config("database.connections.{$connection}.dump.addExtraOption")
            : '<unset>';
        $disk->put("{$name}.sql.gz", gzencode('x', 9));
    });

    return function () use (&$seen): string {
        return $seen;
    };
}
