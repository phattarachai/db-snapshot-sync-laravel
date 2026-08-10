<?php

declare(strict_types=1);

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
