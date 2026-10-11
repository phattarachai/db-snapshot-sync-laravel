<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Compression;

use RuntimeException;

/**
 * A plain PHP stream over a snapshot's SQL, whatever the file is stored in, so
 * callers use fread()/fgets()/fwrite() on $handle and never branch on format.
 *
 * Plain and gzip go through zlib, which reads a plain file as-is and a
 * multi-member gzip to its end. zstd runs the CLI with the file on the other
 * side of a pipe; close() then reports a non-zero exit — a corrupt or truncated
 * file — that the pipe alone would hide as an early EOF.
 */
final class SnapshotStream
{
    /**
     * @param  resource  $handle
     * @param  resource|null  $process
     * @param  resource|null  $stderr
     */
    private function __construct(
        public readonly mixed $handle,
        private readonly string $path,
        private readonly bool $writing = false,
        private mixed $process = null,
        private readonly mixed $stderr = null,
        private bool $closed = false,
    ) {}

    public function __destruct()
    {
        $this->abort();
    }

    /**
     * Open the snapshot at $path for reading its decompressed SQL; the format is
     * sniffed from the file's magic bytes.
     */
    public static function read(string $path): self
    {
        if (Compression::sniff($path) === Compression::Zstd) {
            return self::spawn([...Zstd::fromConfig()->decompressCommand(), $path], $path, writing: false, target: ['file', '/dev/null', 'r']);
        }

        $handle = @gzopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open snapshot for reading: {$path}");
        }

        return new self($handle, $path);
    }

    /**
     * Open $path for writing SQL in $format, appending a new gzip member or zstd
     * frame when $append — both formats decompress concatenated parts as one.
     */
    public static function write(string $path, Compression $format, bool $append = false): self
    {
        $mode = $append ? 'a' : 'w';

        if ($format === Compression::Zstd) {
            return self::spawn(Zstd::fromConfig()->compressCommand(), $path, writing: true, target: ['file', $path, $mode]);
        }

        $handle = $format === Compression::Gzip ? @gzopen($path, "{$mode}b9") : @fopen($path, "{$mode}b");

        if ($handle === false) {
            throw new RuntimeException("Unable to open snapshot for writing: {$path}");
        }

        return new self($handle, $path, writing: true);
    }

    /**
     * The whole decompressed content of the snapshot at $path.
     */
    public static function contents(string $path): string
    {
        $stream = self::read($path);
        $contents = (string) stream_get_contents($stream->handle);
        $stream->close();

        return $contents;
    }

    /**
     * Close the stream. A zstd reader that reached EOF, or any zstd writer,
     * throws when the CLI failed; a reader closed early stops zstd and does not.
     * Safe to call more than once.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->process === null) {
            fclose($this->handle);

            return;
        }

        // A reader stopped before EOF: zstd is still writing into the pipe.
        $abandoned = ! $this->writing && ! feof($this->handle);

        if ($abandoned) {
            proc_terminate($this->process);
        }

        fclose($this->handle);
        $exit = proc_close($this->process);
        $this->process = null;

        $error = trim((string) stream_get_contents($this->stderr, 2000, 0));
        fclose($this->stderr);

        if ($exit !== 0 && ! $abandoned) {
            throw new RuntimeException("zstd failed on {$this->path} (exit {$exit})".($error !== '' ? ": {$error}" : '.'));
        }
    }

    /**
     * Close the stream on an error path, never throwing: the caller is already
     * failing, and a half-written file is discarded anyway.
     */
    public function abort(): void
    {
        try {
            $this->close();
        } catch (RuntimeException) {
            // The caller's own failure is the one worth reporting.
        }
    }

    /**
     * @param  list<string>  $command
     * @param  array{0: string, 1: string, 2: string}  $target  the descriptor zstd reads from (writing) or writes to (reading)
     */
    private static function spawn(array $command, string $path, bool $writing, array $target): self
    {
        $stderr = tmpfile();

        if ($stderr === false) {
            throw new RuntimeException('Unable to create a temporary file for zstd\'s errors.');
        }

        $descriptors = $writing
            ? [0 => ['pipe', 'r'], 1 => $target, 2 => $stderr]
            : [0 => $target, 1 => ['pipe', 'w'], 2 => $stderr];

        $process = proc_open($command, $descriptors, $pipes);

        if ($process === false) {
            throw new RuntimeException("Could not run [{$command[0]}] for {$path}.");
        }

        return new self($writing ? $pipes[0] : $pipes[1], $path, $writing, $process, $stderr);
    }
}
