<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Phattarachai\DbSnapshotSyncLaravel\Sanitizers\PostgresSanitizer;

beforeEach(function (): void {
    $this->disk = Storage::fake('snapshots');
});

$dump = <<<'SQL'
\restrict abc123
SET statement_timeout = 0;
SET transaction_timeout = 0;
CREATE TABLE foo (id int);
ALTER TABLE foo OWNER TO postgres;
\unrestrict abc123
SQL;

it('strips prefixed psql directives in place and keeps real SQL', function () use ($dump): void {
    $this->disk->put('snap.sql', $dump);

    $sanitizer = new PostgresSanitizer(['\\restrict', '\\unrestrict', 'SET transaction_timeout']);
    $out = $sanitizer->sanitize($this->disk, 'snap.sql');

    expect($out)->toBe('snap.sql');

    $result = $this->disk->get('snap.sql');
    expect($result)
        ->not->toContain('\\restrict')
        ->not->toContain('\\unrestrict')
        ->not->toContain('transaction_timeout')
        ->toContain('CREATE TABLE foo')
        ->toContain('SET statement_timeout');
});

it('drops lines by substring via drop_contains', function () use ($dump): void {
    $this->disk->put('snap.sql', $dump);

    (new PostgresSanitizer([], [' OWNER TO ']))->sanitize($this->disk, 'snap.sql');

    expect($this->disk->get('snap.sql'))
        ->not->toContain('OWNER TO')
        ->toContain('CREATE TABLE foo');
});

it('applies whole-content regex patterns', function (): void {
    $this->disk->put('snap.sql', "GRANT ALL ON foo TO \"claude-prod\";\nCREATE TABLE foo (id int);\n");

    (new PostgresSanitizer([], [], ['/^GRANT\b[^;]*"claude-[^"]*"[^;]*;\n?/m']))->sanitize($this->disk, 'snap.sql');

    expect($this->disk->get('snap.sql'))
        ->not->toContain('claude-prod')
        ->toContain('CREATE TABLE foo');
});

it('round-trips gzipped snapshots', function () use ($dump): void {
    $this->disk->put('snap.sql.gz', gzencode($dump, 9));

    $out = (new PostgresSanitizer(['\\restrict', '\\unrestrict']))->sanitize($this->disk, 'snap.sql.gz');

    expect($out)->toBe('snap.sql.gz');
    expect(gzdecode($this->disk->get('snap.sql.gz')))
        ->not->toContain('\\restrict')
        ->toContain('CREATE TABLE foo');
});
