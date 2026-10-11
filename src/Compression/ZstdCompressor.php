<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Compression;

use Spatie\DbDumper\Compressors\Compressor;

/**
 * db-dumper pipes the dump through useCommand() in a shell (`dump | <command> > file`).
 */
final readonly class ZstdCompressor implements Compressor
{
    public function __construct(private Zstd $zstd) {}

    public function useCommand(): string
    {
        return implode(' ', array_map(escapeshellarg(...), $this->zstd->compressCommand()));
    }

    public function useExtension(): string
    {
        return 'zst';
    }
}
