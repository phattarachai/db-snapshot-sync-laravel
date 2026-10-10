<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Phattarachai\DbSnapshotSyncLaravel\Backup\BackupHealth;

/**
 * `snapshot:backup-check` as a queued job. A stale target is reported through
 * the SnapshotBackupStale event, not by failing the job.
 */
class CheckSnapshotBackup implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue(config('db-snapshot-sync.backup.queue'));
    }

    public function handle(BackupHealth $health): void
    {
        $health->check();
    }
}
