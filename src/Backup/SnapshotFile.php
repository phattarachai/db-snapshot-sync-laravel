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
     * When the snapshot was taken: the `Y-m-d_H-i-s` stamp spatie puts in the
     * name (in the app's timezone), else the file's last-modified time.
     *
     * On a target, last-modified is the upload time, so a catch-up run that
     * uploads an old snapshot last would otherwise pass it off as the newest.
     */
    public function takenAt(): int
    {
        if (preg_match_all('/\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}/', $this->name(), $matches) === 0) {
            return $this->lastModified;
        }

        $stamp = end($matches[0]);
        $taken = Carbon::createFromFormat('!Y-m-d_H-i-s', $stamp, (string) config('app.timezone', 'UTC'));

        return $taken !== null && $taken->format('Y-m-d_H-i-s') === $stamp ? $taken->getTimestamp() : $this->lastModified;
    }

    /**
     * The ISO week the snapshot was taken in, in the app's timezone, e.g. 2026-W41.
     */
    public function isoWeek(): string
    {
        $taken = Carbon::createFromTimestamp($this->takenAt(), (string) config('app.timezone', 'UTC'));

        return sprintf('%04d-W%02d', $taken->isoWeekYear(), $taken->isoWeek());
    }

    public function isOlderThan(Carbon $cutoff): bool
    {
        return $this->lastModified < $cutoff->getTimestamp();
    }
}
