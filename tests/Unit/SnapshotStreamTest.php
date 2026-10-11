<?php

declare(strict_types=1);

use Phattarachai\DbSnapshotSyncLaravel\Compression\Compression;
use Phattarachai\DbSnapshotSyncLaravel\Compression\SnapshotStream;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/snapshot-stream-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->dir.'/*') ?: []);
    rmdir($this->dir);
});

it('sniffs the format from magic bytes, not the name', function (string $content, Compression $format): void {
    file_put_contents($this->dir.'/snap.sql.gz', $content);

    expect(Compression::sniff($this->dir.'/snap.sql.gz'))->toBe($format);
})->with([
    'plain' => fn (): array => ["CREATE TABLE foo (id int);\n", Compression::None],
    'gzip' => fn (): array => [gzencode('x', 9), Compression::Gzip],
    'zstd' => fn (): array => [zstd('x'), Compression::Zstd],
])->skip(fn (): bool => zstdMissing(), 'zstd not available');

it('names snapshots by .sql, .sql.gz and .sql.zst', function (): void {
    expect(Compression::isSnapshotName('a.sql.zst'))->toBeTrue()
        ->and(Compression::isSnapshotName('a.sql.gz'))->toBeTrue()
        ->and(Compression::isSnapshotName('a.sql'))->toBeTrue()
        ->and(Compression::isSnapshotName('a.zst'))->toBeFalse()
        ->and(Compression::isSnapshotName('a.sql.zst.download'))->toBeFalse()
        ->and(Compression::stem('2026-10-12_03-00-00.sql.zst'))->toBe('2026-10-12_03-00-00')
        ->and(Compression::Zstd->suffix())->toBe('.sql.zst');
});

it('rejects an unknown configured format', function (): void {
    config(['db-snapshot-sync.compression.format' => 'brotli']);

    Compression::configured();
})->throws(InvalidArgumentException::class, 'must be gzip or zstd');

it('writes zstd the CLI reads, and reads back what it wrote, appended frames included', function (): void {
    $path = $this->dir.'/snap.sql.zst';

    $first = SnapshotStream::write($path, Compression::Zstd);
    fwrite($first->handle, "CREATE TABLE foo (id int);\n");
    $first->close();

    $second = SnapshotStream::write($path, Compression::Zstd, append: true);
    fwrite($second->handle, "CREATE TABLE bar (id int);\n");
    $second->close();

    expect(Compression::sniff($path))->toBe(Compression::Zstd)
        ->and(zstd((string) file_get_contents($path), decompress: true))->toBe("CREATE TABLE foo (id int);\nCREATE TABLE bar (id int);\n")
        ->and(SnapshotStream::contents($path))->toBe("CREATE TABLE foo (id int);\nCREATE TABLE bar (id int);\n");
})->skip(fn (): bool => zstdMissing(), 'zstd not available');

it('reads plain and gzip snapshots through the same stream', function (): void {
    file_put_contents($this->dir.'/plain.sql', 'plain');
    file_put_contents($this->dir.'/packed.sql.gz', gzencode('packed', 9));

    expect(SnapshotStream::contents($this->dir.'/plain.sql'))->toBe('plain')
        ->and(SnapshotStream::contents($this->dir.'/packed.sql.gz'))->toBe('packed');
});

it('fails on a truncated zstd file once it has been read to the end', function (): void {
    $packed = zstd(str_repeat("INSERT INTO foo VALUES (1, 'row');\n", 20000));
    file_put_contents($this->dir.'/cut.sql.zst', substr($packed, 0, intdiv(strlen($packed), 2)));

    SnapshotStream::contents($this->dir.'/cut.sql.zst');
})->throws(RuntimeException::class, 'zstd failed')->skip(fn (): bool => zstdMissing(), 'zstd not available');

it('does not fail when a reader stops early', function (): void {
    file_put_contents($this->dir.'/big.sql.zst', zstd(str_repeat("INSERT INTO foo VALUES (1, 'row');\n", 200000)));

    $stream = SnapshotStream::read($this->dir.'/big.sql.zst');
    $head = fread($stream->handle, 100);
    $stream->close();

    expect($head)->toStartWith('INSERT INTO foo');
})->skip(fn (): bool => zstdMissing(), 'zstd not available');
