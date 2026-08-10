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
