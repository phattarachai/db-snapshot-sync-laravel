<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Phattarachai\DbSnapshotSyncLaravel\Backup\OffsiteBackup;
use Phattarachai\DbSnapshotSyncLaravel\Backup\TargetReport;
use RuntimeException;

/**
 * `snapshot:backup` as a queued job. Fails (once, no retry) when any target
 * failed; the next run catches up whatever this one missed.
 */
class BackupSnapshots implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct()
    {
        $this->onQueue(config('db-snapshot-sync.backup.queue'));
    }

    public function handle(OffsiteBackup $backup): void
    {
        $failed = array_filter($backup->run(), fn (TargetReport $report): bool => $report->failed());

        if ($failed !== []) {
            throw new RuntimeException('Snapshot backup failed: '.implode('; ', array_map(
                fn (TargetReport $report): string => "[{$report->disk}] {$report->error}",
                $failed,
            )));
        }
    }
}
