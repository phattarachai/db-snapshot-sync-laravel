<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Backup;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;

final class Snapshots
{
    /**
     * Every .sql / .sql.gz directly under $directory on $disk, newest first.
     *
     * @return Collection<int, SnapshotFile>
     */
    public static function on(Filesystem $disk, string $directory = ''): Collection
    {
        return collect($disk->files($directory))
            ->filter(SnapshotFile::isSnapshot(...))
            ->map(fn (string $path): SnapshotFile => new SnapshotFile($path, $disk->size($path), $disk->lastModified($path)))
            ->sortByDesc(fn (SnapshotFile $file): int => $file->lastModified)
            ->values();
    }
}
