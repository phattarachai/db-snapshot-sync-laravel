<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Console;

use Illuminate\Console\Command;
use Phattarachai\DbSnapshotSyncLaravel\Backup\DrillResult;
use Phattarachai\DbSnapshotSyncLaravel\Backup\RestoreDrill;

class DrillCommand extends Command
{
    protected $signature = 'snapshot:drill
        {--disk= : Backup disk to restore from (default: the first of db-snapshot-sync.backup.disks)}
        {--connection= : Live connection to compare with; the scratch database is created on its server (default: the default connection)}';

    protected $description = 'Restore the newest off-site copy into a scratch database, compare row counts with the live one, then drop it.';

    public function handle(RestoreDrill $drill): int
    {
        $disk = $this->option('disk');
        $connection = $this->option('connection');

        $result = $drill->run(
            disk: is_string($disk) && $disk !== '' ? $disk : null,
            connection: is_string($connection) && $connection !== '' ? $connection : null,
            note: fn (string $line) => $this->line($line),
        );

        $this->report($result);

        return $result->passed() ? self::SUCCESS : self::FAILURE;
    }

    private function report(DrillResult $result): void
    {
        $this->newLine();
        $this->line("Snapshot: [{$result->disk}] ".($result->snapshot ?? '—'));
        $this->line("Restored into {$result->scratchDatabase}, compared with [{$result->connection}].");
        $this->line(sprintf('Elapsed: download %.2fs · load %.2fs · total %.2fs', $result->downloadSeconds, $result->loadSeconds, $result->totalSeconds));

        $differences = $result->differences();

        if ($differences !== []) {
            $this->table(['Table', 'Live', 'Restored', 'Δ'], array_map(fn (array $row): array => [
                $row['table'],
                $row['live'] ?? 'missing',
                $row['restored'] ?? 'missing',
                $row['live'] !== null && $row['restored'] !== null ? sprintf('%+d', $row['restored'] - $row['live']) : '',
            ], $differences));
        }

        $this->line(sprintf('%d table(s) compared, %d identical.', count($result->tables), count($result->tables) - count($differences)));

        foreach ($result->missing as $table) {
            $this->error("Missing from the restore: {$table}");
        }

        foreach ($result->emptied as $table) {
            $this->error("Restored empty, has rows live: {$table}");
        }

        if ($result->error !== null) {
            $this->error($result->error);
        }

        $result->passed()
            ? $this->info('Drill PASSED.')
            : $this->error('Drill FAILED.');
    }
}
