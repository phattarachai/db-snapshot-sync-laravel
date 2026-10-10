<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Backup;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Throwable;

final class Snapshots
{
    /**
     * Every .sql / .sql.gz directly under $directory on $disk, newest taken first
     * (see SnapshotFile::takenAt()), the later upload first on a tie.
     *
     * @return Collection<int, SnapshotFile>
     */
    public static function on(Filesystem $disk, string $directory = ''): Collection
    {
        return collect(self::files($disk, $directory))
            ->filter(SnapshotFile::isSnapshot(...))
            ->map(fn (string $path): SnapshotFile => new SnapshotFile($path, $disk->size($path), $disk->lastModified($path)))
            ->sort(fn (SnapshotFile $a, SnapshotFile $b): int => [$b->takenAt(), $b->lastModified] <=> [$a->takenAt(), $a->lastModified])
            ->values();
    }

    /**
     * The files directly under $directory, none when it does not exist.
     *
     * Local and S3 disks list a missing directory as empty, but some adapters
     * (Google Drive) throw instead. The listing error stands unless the disk
     * itself says the directory is missing, so an unreachable disk still fails.
     *
     * @return list<string>
     */
    public static function files(Filesystem $disk, string $directory): array
    {
        try {
            return array_values($disk->files($directory));
        } catch (Throwable $e) {
            if ($directory !== '' && self::isMissing($disk, $directory)) {
                return [];
            }

            throw $e;
        }
    }

    private static function isMissing(Filesystem $disk, string $directory): bool
    {
        try {
            return ! $disk->exists($directory);
        } catch (Throwable) {
            return false;
        }
    }
}
