<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Phattarachai\DbSnapshotSyncLaravel\Backup\RestoreDrill;
use RuntimeException;

/**
 * `snapshot:drill` as a queued job. The result goes out as SnapshotDrillCompleted
 * either way; a failed drill also fails the job. The live connection is
 * $databaseConnection: Queueable already owns $connection (the queue's).
 */
class RunRestoreDrill implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly ?string $disk = null, public readonly ?string $databaseConnection = null)
    {
        $this->onQueue(config('db-snapshot-sync.backup.queue'));
    }

    public function handle(RestoreDrill $drill): void
    {
        $result = $drill->run($this->disk, $this->databaseConnection);

        if (! $result->passed()) {
            throw new RuntimeException('Restore drill failed: '.($result->error ?? 'missing tables ['.implode(', ', $result->missing).'], emptied tables ['.implode(', ', $result->emptied).']'));
        }
    }
}
