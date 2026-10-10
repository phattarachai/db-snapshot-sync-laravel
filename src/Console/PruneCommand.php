<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Console;

use Illuminate\Console\Command;
use Phattarachai\DbSnapshotSyncLaravel\Backup\LocalPruner;

class PruneCommand extends Command
{
    protected $signature = 'snapshot:prune
        {--days= : Delete local snapshots older than this many days (default: db-snapshot-sync.backup.local_days)}
        {--dry-run : List what would be deleted without deleting it}';

    protected $description = 'Delete local snapshots by age, keeping the newest keep_min and every protected snapshot.';

    public function handle(LocalPruner $pruner): int
    {
        $days = $this->option('days');

        if ($days !== null && (! ctype_digit((string) $days) || (int) $days < 1)) {
            $this->error('--days must be a whole number of at least 1.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $deleted = $pruner->prune($days === null ? null : (int) $days, $dryRun);

        foreach ($deleted as $path) {
            $this->line(($dryRun ? 'Would delete ' : 'Deleted ').$path);
        }

        $this->info(($dryRun ? 'Would prune ' : 'Pruned ').count($deleted).' snapshot(s).');

        return self::SUCCESS;
    }
}
