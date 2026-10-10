<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Backup;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Phattarachai\DbSnapshotSyncLaravel\Events\SnapshotBackupHealthy;
use Phattarachai\DbSnapshotSyncLaravel\Events\SnapshotBackupStale;
use Phattarachai\DbSnapshotSyncLaravel\Events\SnapshotBackupStatus;
use RuntimeException;
use Throwable;

/**
 * Reads the newest object under `{path}/daily/` on each target and dispatches
 * SnapshotBackupHealthy or SnapshotBackupStale for it. A target that holds no
 * copy (no daily/ folder yet included), or cannot be read at all, is stale.
 */
final class BackupHealth
{
    /**
     * @return list<SnapshotBackupStatus> one per target, each already dispatched
     */
    public function check(?BackupConfig $config = null): array
    {
        $config ??= BackupConfig::fromConfig();

        if ($config->disks === []) {
            throw new RuntimeException('No backup target disks are configured (db-snapshot-sync.backup.disks).');
        }

        return array_map(function (string $disk) use ($config): SnapshotBackupStatus {
            $status = $this->inspect($disk, $config);

            event($status);

            return $status;
        }, $config->disks);
    }

    private function inspect(string $disk, BackupConfig $config): SnapshotBackupStatus
    {
        try {
            $newest = Snapshots::on(Storage::disk($disk), $config->dailyDir())->first();
        } catch (Throwable $e) {
            return new SnapshotBackupStale($disk, null, null, null, $config->staleAfterHours, $e->getMessage());
        }

        if ($newest === null) {
            return new SnapshotBackupStale($disk, null, null, null, $config->staleAfterHours);
        }

        $at = Carbon::createFromTimestamp($newest->lastModified, (string) config('app.timezone', 'UTC'));
        $age = max(0, Carbon::now()->getTimestamp() - $newest->lastModified);
        $class = $age > $config->staleAfterHours * 3600 ? SnapshotBackupStale::class : SnapshotBackupHealthy::class;

        return new $class($disk, $newest->path, $at, $age, $config->staleAfterHours);
    }
}
