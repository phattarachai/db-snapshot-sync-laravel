<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Sanitizers;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
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
     * dump never has to sit in memory as a whole string. Gzip is detected by the
     * file's magic bytes, not its name — a source may serve a plain dump under a
     * .sql.gz name — and the input's format is preserved on write.
     */
    private function streamLineFilters(Filesystem $disk, string $file): void
    {
        $path = $this->localPath($disk, $file);
        $isGz = $this->isGzipped($path);
        $temp = $path.'.sanitizing';

        $in = $isGz ? gzopen($path, 'rb') : fopen($path, 'rb');
        if ($in === false) {
            throw new RuntimeException("Unable to open snapshot for sanitizing: {$path}");
        }

        $out = $isGz ? gzopen($temp, 'wb9') : fopen($temp, 'wb');
        if ($out === false) {
            $isGz ? gzclose($in) : fclose($in);

            throw new RuntimeException("Unable to write sanitized snapshot: {$temp}");
        }

        while (($line = $isGz ? gzgets($in) : fgets($in)) !== false) {
            if ($this->matchesPrefix($line) || $this->matchesContains($line)) {
                continue;
            }

            $isGz ? gzwrite($out, $line) : fwrite($out, $line);
        }

        $isGz ? gzclose($in) : fclose($in);
        $isGz ? gzclose($out) : fclose($out);

        rename($temp, $path);
    }

    private function applyPatterns(Filesystem $disk, string $file): void
    {
        $raw = (string) $disk->get($file);
        $isGz = str_starts_with($raw, "\x1f\x8b");
        $content = $isGz ? (string) gzdecode($raw) : $raw;

        foreach ($this->dropPatterns as $pattern) {
            $content = (string) preg_replace($pattern, '', $content);
        }

        $disk->put($file, $isGz ? (string) gzencode($content, 9) : $content);
    }

    private function isGzipped(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $magic = (string) fread($handle, 2);
        fclose($handle);

        return $magic === "\x1f\x8b";
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
