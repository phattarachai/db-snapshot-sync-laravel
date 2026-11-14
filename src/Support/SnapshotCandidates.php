<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Phattarachai\DbSnapshotSyncLaravel\Backup\SnapshotFile;

/**
 * The snapshots on a disk that may leave the box: snapshot files (or the names
 * $pattern matches), minus every name holding a rejected fragment, newest first.
 */
final class SnapshotCandidates
{
    /**
     * @return Collection<int, string>
     */
    public static function on(Filesystem $disk, ?string $pattern = null): Collection
    {
        $reject = self::rejected();

        return collect($disk->files())
            ->filter(fn (string $file): bool => $pattern === null ? SnapshotFile::isSnapshot($file) : fnmatch($pattern, basename($file)))
            ->reject(fn (string $file): bool => array_any($reject, fn (string $fragment): bool => str_contains($file, $fragment)))
            ->sortByDesc(fn (string $file): int => $disk->lastModified($file))
            ->values();
    }

    public static function newest(Filesystem $disk, ?string $pattern = null): ?string
    {
        return self::on($disk, $pattern)->first();
    }

    /**
     * `export.reject`, plus `api.reject` from a config published before 1.6.
     *
     * @return list<string>
     */
    private static function rejected(): array
    {
        return array_values(array_unique(array_filter(array_map('strval', [
            ...(array) config('db-snapshot-sync.export.reject', []),
            ...(array) config('db-snapshot-sync.api.reject', []),
        ]))));
    }
}
