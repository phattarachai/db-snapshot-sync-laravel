<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Backup;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Phattarachai\DbSnapshotSyncLaravel\Events\SnapshotBackupHealthy;
use Phattarachai\DbSnapshotSyncLaravel\Events\SnapshotBackupStale;
use Phattarachai\DbSnapshotSyncLaravel\Events\SnapshotBackupStatus;
use RuntimeException;
use Throwable;

/**
 * Reads the newest snapshot (by SnapshotFile::takenAt()) under `{path}/daily/` on each target and dispatches
 * SnapshotBackupHealthy or SnapshotBackupStale for it. A target that holds no
 * copy (no daily/ folder yet included), or cannot be read at all, is stale.
 * An empty or failed listing is read again first, since a transient Drive failure looks the same.
 */
final class BackupHealth
{
    private const int READ_ATTEMPTS = 3;

    private const int SECONDS_BETWEEN_READS = 5;

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
            $newest = $this->readNewest($disk, $config);
        } catch (Throwable $e) {
            return new SnapshotBackupStale($disk, null, null, null, $config->staleAfterHours, $e->getMessage());
        }

        if ($newest === null) {
            return new SnapshotBackupStale($disk, null, null, null, $config->staleAfterHours);
        }

        $at = Carbon::createFromTimestamp($newest->takenAt(), (string) config('app.timezone', 'UTC'));
        $age = max(0, Carbon::now()->getTimestamp() - $newest->takenAt());
        $class = $age > $config->staleAfterHours * 3600 ? SnapshotBackupStale::class : SnapshotBackupHealthy::class;

        return new $class($disk, $newest->path, $at, $age, $config->staleAfterHours);
    }

    private function readNewest(string $disk, BackupConfig $config, int $attempt = 1): ?SnapshotFile
    {
        $isLastAttempt = $attempt === self::READ_ATTEMPTS;

        try {
            $newest = Snapshots::on(Storage::disk($disk), $config->dailyDir())->first();
        } catch (Throwable $e) {
            throw_if($isLastAttempt, $e);

            $newest = null;
        }

        if ($newest !== null || $isLastAttempt) {
            return $newest;
        }

        Sleep::for(self::SECONDS_BETWEEN_READS)->seconds();
        $this->forgetResolvedDisk($disk);

        return $this->readNewest($disk, $config, $attempt + 1);
    }

    /**
     * Drops the instance so an adapter that memoizes requests really reads again. A disk
     * with no config (Storage::set) could not be rebuilt, so it stays.
     */
    private function forgetResolvedDisk(string $disk): void
    {
        if (config("filesystems.disks.{$disk}") !== null) {
            Storage::forgetDisk($disk);
        }
    }
}
