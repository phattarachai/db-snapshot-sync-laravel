<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Sanitizers;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Phattarachai\DbSnapshotSyncLaravel\Compression\Compression;
use Phattarachai\DbSnapshotSyncLaravel\Compression\SnapshotStream;
use RuntimeException;

final class PostgresSanitizer implements Sanitizer
{
    /**
     * @param  list<string>  $dropPrefixes
     * @param  list<string>  $dropContains
     * @param  list<string>  $dropPatterns
     */
    public function __construct(
        private array $dropPrefixes = [],
        private array $dropContains = [],
        private array $dropPatterns = [],
    ) {}

    public function sanitize(Filesystem $disk, string $file): string
    {
        if ($this->dropPrefixes !== [] || $this->dropContains !== []) {
            $this->streamLineFilters($disk, $file);
        }

        // Whole-content regexes can't be applied line-by-line, so this path loads
        // the file into memory. It only runs when drop_patterns is configured
        // (empty by default), keeping the common case streaming and memory-safe.
        if ($this->dropPatterns !== []) {
            $this->applyPatterns($disk, $file);
        }

        return $file;
    }

    /**
     * Copy the dump line-by-line, dropping matched lines, so an arbitrarily large
     * dump never has to sit in memory as a whole string. The format (plain, gzip
     * or zstd) is detected by the file's magic bytes, not its name — a source may
     * serve a plain dump under a .sql.gz name — and is preserved on write.
     */
    private function streamLineFilters(Filesystem $disk, string $file): void
    {
        $path = $this->localPath($disk, $file);
        $temp = $path.'.sanitizing';

        $in = SnapshotStream::read($path);
        $out = SnapshotStream::write($temp, Compression::sniff($path));

        try {
            while (($line = fgets($in->handle)) !== false) {
                if ($this->matchesPrefix($line) || $this->matchesContains($line)) {
                    continue;
                }

                fwrite($out->handle, $line);
            }

            $in->close();
            $out->close();
        } catch (RuntimeException $e) {
            $in->abort();
            $out->abort();
            @unlink($temp);

            throw $e;
        }

        rename($temp, $path);
    }

    private function applyPatterns(Filesystem $disk, string $file): void
    {
        $path = $this->localPath($disk, $file);
        $content = SnapshotStream::contents($path);

        foreach ($this->dropPatterns as $pattern) {
            $content = (string) preg_replace($pattern, '', $content);
        }

        $temp = $path.'.sanitizing';
        $out = SnapshotStream::write($temp, Compression::sniff($path));
        fwrite($out->handle, $content);
        $out->close();

        rename($temp, $path);
    }

    private function matchesPrefix(string $line): bool
    {
        $line = rtrim($line, "\r\n");

        return array_any($this->dropPrefixes, fn (string $prefix): bool => str_starts_with($line, $prefix));
    }

    private function matchesContains(string $line): bool
    {
        return array_any($this->dropContains, fn (string $needle): bool => str_contains($line, $needle));
    }

    private function localPath(Filesystem $disk, string $file): string
    {
        if (! $disk instanceof FilesystemAdapter) {
            throw new RuntimeException('The snapshots disk must be a local filesystem to sanitize by streaming.');
        }

        return $disk->path($file);
    }
}
