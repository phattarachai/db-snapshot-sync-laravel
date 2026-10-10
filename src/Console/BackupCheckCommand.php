<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Console;

use Illuminate\Console\Command;
use Phattarachai\DbSnapshotSyncLaravel\Backup\BackupHealth;
use Phattarachai\DbSnapshotSyncLaravel\Events\SnapshotBackupStale;
use Phattarachai\DbSnapshotSyncLaravel\Events\SnapshotBackupStatus;
use RuntimeException;

class BackupCheckCommand extends Command
{
    protected $signature = 'snapshot:backup-check';

    protected $description = 'Check each off-site backup disk\'s newest daily copy; dispatch SnapshotBackupStale / SnapshotBackupHealthy and fail when stale.';

    public function handle(BackupHealth $health): int
    {
        try {
            $statuses = $health->check();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($statuses as $status) {
            $status instanceof SnapshotBackupStale
                ? $this->error('STALE '.$status->describe())
                : $this->info('OK '.$status->describe());
        }

        return array_any($statuses, fn (SnapshotBackupStatus $status): bool => $status instanceof SnapshotBackupStale)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
