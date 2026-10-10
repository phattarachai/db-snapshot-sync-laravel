<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Backup;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Copies the local snapshots to every target disk and ages the targets out.
 *
 * Each target gets every snapshot from the last `daily_days` under
 * `{path}/daily/` (by name, so a night the box missed is caught up on the next
 * run) and the newest snapshot of each of the last `weekly_weeks` ISO weeks
 * under `{path}/weekly/{YYYY-Www}_{name}`. Files are streamed, never read into
 * memory, written with private visibility whatever the disk's default, and
 * checked for size afterwards — a short copy is deleted and fails the target.
 *
 * One target failing does not stop the others; the caller reads the reports.
 */
final class OffsiteBackup
{
    /**
     * spatie dumps to a temp file and then streams it onto the snapshots disk, so
     * a snapshot touched this recently may still be mid-copy. It goes up next run.
     */
    public const int SETTLE_SECONDS = 60;

    /**
     * @param  (Closure(string): void)|null  $note  progress lines, e.g. for console output
     * @return list<TargetReport>
     */
    public function run(?BackupConfig $config = null, ?Closure $note = null): array
    {
        $config ??= BackupConfig::fromConfig();
        $note ??= static function (string $line): void {};

        if ($config->disks === []) {
            throw new RuntimeException('No backup target disks are configured (db-snapshot-sync.backup.disks).');
        }

        $local = Storage::disk($config->localDisk);
        $snapshots = $this->backupable($local, $config);

        return array_map(
            fn (string $disk): TargetReport => $this->backUpTo($disk, $local, $snapshots, $config, $note),
            $config->disks,
        );
    }

    /**
     * @return Collection<int, SnapshotFile>
     */
    private function backupable(Filesystem $local, BackupConfig $config): Collection
    {
        $settled = Carbon::now()->subSeconds(self::SETTLE_SECONDS);

        return Snapshots::on($local)
            ->reject(fn (SnapshotFile $file): bool => $config->protects($file) || $config->rejects($file))
            ->filter(fn (SnapshotFile $file): bool => $file->isOlderThan($settled))
            ->values();
    }

    /**
     * @param  Collection<int, SnapshotFile>  $snapshots
     * @param  Closure(string): void  $note
     */
    private function backUpTo(string $disk, Filesystem $local, Collection $snapshots, BackupConfig $config, Closure $note): TargetReport
    {
        $report = new TargetReport($disk);

        try {
            $target = Storage::disk($disk);

            $this->uploadDaily($local, $target, $snapshots, $config, $report, $note);
            $this->copyWeekly($local, $target, $snapshots, $config, $report, $note);
            $this->prune($target, $config, $report, $note);
        } catch (Throwable $e) {
            $report->error = $e->getMessage();
        }

        return $report;
    }

    /**
     * @param  Collection<int, SnapshotFile>  $snapshots
     * @param  Closure(string): void  $note
     */
    private function uploadDaily(Filesystem $local, Filesystem $target, Collection $snapshots, BackupConfig $config, TargetReport $report, Closure $note): void
    {
        $since = Carbon::now()->subDays($config->dailyDays);

        foreach ($snapshots->reject(fn (SnapshotFile $file): bool => $file->isOlderThan($since)) as $file) {
            $to = "{$config->dailyDir()}/{$file->name()}";

            if ($this->holds($target, $to, $file->size)) {
                continue;
            }

            $this->stream($local, $file, $target, $to);
            $report->uploaded[] = $to;
            $note("[{$report->disk}] uploaded {$to}");
        }
    }

    /**
     * @param  Collection<int, SnapshotFile>  $snapshots
     * @param  Closure(string): void  $note
     */
    private function copyWeekly(Filesystem $local, Filesystem $target, Collection $snapshots, BackupConfig $config, TargetReport $report, Closure $note): void
    {
        if ($config->weeklyWeeks === 0) {
            return;
        }

        $firstWeek = self::firstKeptWeek($config->weeklyWeeks);
        $existing = $target->files($config->weeklyDir());

        // $snapshots is newest first, so the first of each week is its newest.
        $newestPerWeek = $snapshots
            ->groupBy(fn (SnapshotFile $file): string => $file->isoWeek())
            ->map(fn (Collection $week): SnapshotFile => $week->first());

        foreach ($newestPerWeek as $week => $file) {
            if (self::weekStart((string) $week)->lt($firstWeek)) {
                continue;
            }

            $to = "{$config->weeklyDir()}/{$week}_{$file->name()}";

            if (! $this->holds($target, $to, $file->size)) {
                $from = "{$config->dailyDir()}/{$file->name()}";

                if (! ($this->holds($target, $from, $file->size) && $this->copyWithin($target, $from, $to, $file->size))) {
                    $this->stream($local, $file, $target, $to);
                }

                $report->weekly[] = $to;
                $note("[{$report->disk}] weekly {$to}");
            }

            // An earlier snapshot of the same week, copied before a newer one existed.
            foreach ($existing as $path) {
                if ($path !== $to && str_starts_with(basename($path), "{$week}_")) {
                    $target->delete($path);
                    $report->pruned[] = $path;
                    $note("[{$report->disk}] replaced {$path}");
                }
            }
        }
    }

    /**
     * Daily copies age out by their time on the target, weekly copies by the week
     * in their name; neither below the newest `keep_min`.
     *
     * @param  Closure(string): void  $note
     */
    private function prune(Filesystem $target, BackupConfig $config, TargetReport $report, Closure $note): void
    {
        $cutoff = Carbon::now()->subDays($config->dailyDays);

        $daily = Snapshots::on($target, $config->dailyDir())
            ->slice($config->keepMin)
            ->filter(fn (SnapshotFile $file): bool => $file->isOlderThan($cutoff))
            ->map(fn (SnapshotFile $file): string => $file->path);

        $firstWeek = self::firstKeptWeek(max(1, $config->weeklyWeeks));

        $weekly = collect($target->files($config->weeklyDir()))
            ->filter(fn (string $path): bool => SnapshotFile::isSnapshot($path) && self::weekOf($path) !== null)
            ->sortByDesc(fn (string $path): string => (string) self::weekOf($path))
            ->values()
            ->slice($config->keepMin)
            ->filter(fn (string $path): bool => $config->weeklyWeeks === 0 || self::weekStart((string) self::weekOf($path))->lt($firstWeek));

        foreach ($daily->merge($weekly) as $path) {
            $target->delete($path);
            $report->pruned[] = $path;
            $note("[{$report->disk}] pruned {$path}");
        }
    }

    private function holds(Filesystem $target, string $path, int $size): bool
    {
        return $target->exists($path) && $target->size($path) === $size;
    }

    /**
     * A same-disk copy (server-side on S3/Spaces), so the current week's weekly
     * copy is not uploaded a second time each night.
     */
    private function copyWithin(Filesystem $target, string $from, string $to, int $size): bool
    {
        if (! $target->copy($from, $to)) {
            return false;
        }

        if (! $target->setVisibility($to, Filesystem::VISIBILITY_PRIVATE)) {
            $target->delete($to);

            throw new RuntimeException("Could not make {$to} private; the copy was deleted.");
        }

        $this->verify($target, $to, $size);

        return true;
    }

    private function stream(Filesystem $local, SnapshotFile $file, Filesystem $target, string $to): void
    {
        $stream = $local->readStream($file->path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Could not open {$file->path} on the snapshots disk.");
        }

        try {
            $written = $target->writeStream($to, $stream, ['visibility' => Filesystem::VISIBILITY_PRIVATE]);
        } finally {
            // Some adapters (the AWS SDK) close the stream themselves once it is sent.
            if (get_resource_type($stream) === 'stream') {
                fclose($stream);
            }
        }

        if ($written === false) {
            throw new RuntimeException("Writing {$to} failed.");
        }

        $this->verify($target, $to, $file->size);
    }

    /**
     * A short copy must never pass for a restore point.
     */
    private function verify(Filesystem $target, string $path, int $expected): void
    {
        $actual = $target->size($path);

        if ($actual === $expected) {
            return;
        }

        $target->delete($path);

        throw new RuntimeException("{$path} is {$actual} bytes on the target, expected {$expected}; the partial copy was deleted.");
    }

    /**
     * The ISO week key at the front of a weekly copy's name, e.g. 2026-W41.
     */
    private static function weekOf(string $path): ?string
    {
        return preg_match('/^(\d{4}-W\d{2})_/', basename($path), $match) === 1 ? $match[1] : null;
    }

    private static function weekStart(string $week): Carbon
    {
        [$year, $number] = array_map(intval(...), explode('-W', $week));

        return Carbon::now()->setISODate($year, $number)->startOfDay();
    }

    /**
     * Monday of the oldest of the last $weeks ISO weeks, the current one included.
     */
    private static function firstKeptWeek(int $weeks): Carbon
    {
        $now = Carbon::now();

        return self::weekStart(sprintf('%04d-W%02d', $now->isoWeekYear(), $now->isoWeek()))->subWeeks($weeks - 1);
    }
}
