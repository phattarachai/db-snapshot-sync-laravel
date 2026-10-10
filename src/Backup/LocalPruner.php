<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Backup;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Ages local snapshots out by time rather than spatie's `snapshot:cleanup --keep`
 * file count, which lets a burst of deploy restore points shorten the window.
 *
 * Never deletes a protected snapshot, and never leaves fewer than `keep_min`
 * restore points (anything the API rejects is not one, so it does not count
 * towards the minimum and ages out on its own).
 */
final class LocalPruner
{
    /**
     * @return list<string> the files deleted, or that would be on a dry run
     */
    public function prune(?int $days = null, bool $dryRun = false, ?BackupConfig $config = null): array
    {
        $config ??= BackupConfig::fromConfig();
        $cutoff = Carbon::now()->subDays($days ?? $config->localDays);
        $disk = Storage::disk($config->localDisk);

        $candidates = Snapshots::on($disk)->reject($config->protects(...));

        $kept = $candidates
            ->reject($config->rejects(...))
            ->take($config->keepMin)
            ->map(fn (SnapshotFile $file): string => $file->path)
            ->all();

        $doomed = $candidates
            ->filter(fn (SnapshotFile $file): bool => $file->isOlderThan($cutoff) && ! in_array($file->path, $kept, true))
            ->map(fn (SnapshotFile $file): string => $file->path)
            ->values()
            ->all();

        if (! $dryRun) {
            foreach ($doomed as $path) {
                $disk->delete($path);
            }
        }

        return $doomed;
    }
}
