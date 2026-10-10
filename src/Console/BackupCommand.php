<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Console;

use Illuminate\Console\Command;
use Phattarachai\DbSnapshotSyncLaravel\Backup\OffsiteBackup;
use Phattarachai\DbSnapshotSyncLaravel\Backup\TargetReport;
use RuntimeException;

class BackupCommand extends Command
{
    protected $signature = 'snapshot:backup';

    protected $description = 'Copy the local snapshots to every off-site backup disk (daily + weekly) and prune the targets by age.';

    public function handle(OffsiteBackup $backup): int
    {
        try {
            $reports = $backup->run(note: fn (string $line) => $this->line($line));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($reports as $report) {
            $report->failed()
                ? $this->error("[{$report->disk}] FAILED: {$report->error}")
                : $this->info(sprintf('[%s] ok: %d uploaded, %d weekly, %d pruned.', $report->disk, count($report->uploaded), count($report->weekly), count($report->pruned)));
        }

        return array_any($reports, fn (TargetReport $report): bool => $report->failed()) ? self::FAILURE : self::SUCCESS;
    }
}
