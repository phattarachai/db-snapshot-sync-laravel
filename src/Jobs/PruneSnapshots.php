<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Phattarachai\DbSnapshotSyncLaravel\Backup\LocalPruner;

/**
 * `snapshot:prune` as a queued job.
 */
class PruneSnapshots implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly ?int $days = null)
    {
        $this->onQueue(config('db-snapshot-sync.backup.queue'));
    }

    public function handle(LocalPruner $pruner): void
    {
        $pruner->prune($this->days);
    }
}
