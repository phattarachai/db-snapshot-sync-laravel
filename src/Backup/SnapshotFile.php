<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Backup;

use Illuminate\Support\Carbon;

/**
 * One .sql / .sql.gz object on a disk.
 */
final readonly class SnapshotFile
{
    public function __construct(
        public string $path,
        public int $size,
        public int $lastModified,
    ) {}

    public static function isSnapshot(string $path): bool
    {
        return str_ends_with($path, '.sql.gz') || str_ends_with($path, '.sql');
    }

    public function name(): string
    {
        return basename($this->path);
    }

    /**
     * The snapshot's name as spatie knows it: the file name without .sql / .sql.gz.
     */
    public function stem(): string
    {
        return (string) preg_replace('/\.sql(\.gz)?$/', '', $this->name());
    }

    /**
     * The ISO week the snapshot was taken in, in the app's timezone, e.g. 2026-W41.
     */
    public function isoWeek(): string
    {
        $taken = Carbon::createFromTimestamp($this->lastModified, (string) config('app.timezone', 'UTC'));

        return sprintf('%04d-W%02d', $taken->isoWeekYear(), $taken->isoWeek());
    }

    public function isOlderThan(Carbon $cutoff): bool
    {
        return $this->lastModified < $cutoff->getTimestamp();
    }
}
