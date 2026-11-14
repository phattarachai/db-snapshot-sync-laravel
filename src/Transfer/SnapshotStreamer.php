<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Transfer;

use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;

/**
 * Copies a file on a disk to an open stream in one pass, hashing as it goes.
 * An app can reuse it for an export command of its own (another disk, another
 * dump format) and print the result the same way `snapshot:export` does.
 */
final class SnapshotStreamer
{
    private const int CHUNK_BYTES = 1024 * 1024;

    /**
     * @param  resource  $out
     */
    public function stream(Filesystem $disk, string $file, $out): ExportResult
    {
        $in = $disk->readStream($file);

        if (! is_resource($in)) {
            throw new RuntimeException("Cannot read {$file}.");
        }

        $hash = hash_init('sha256');
        $size = 0;

        try {
            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK_BYTES);

                if ($chunk === false) {
                    throw new RuntimeException("Reading {$file} failed after {$size} bytes.");
                }

                if ($chunk === '') {
                    continue;
                }

                $this->write($out, $chunk, $size);
                hash_update($hash, $chunk);
                $size += strlen($chunk);
            }
        } finally {
            fclose($in);
        }

        fflush($out);

        return new ExportResult(basename($file), $size, hash_final($hash));
    }

    /**
     * @param  resource  $out
     */
    private function write($out, string $chunk, int $offset): void
    {
        $remaining = $chunk;

        while ($remaining !== '') {
            $written = fwrite($out, $remaining);

            if ($written === false || $written === 0) {
                throw new RuntimeException("Writing the snapshot failed after {$offset} bytes (the reader went away?).");
            }

            $offset += $written;
            $remaining = substr($remaining, $written);
        }
    }
}
