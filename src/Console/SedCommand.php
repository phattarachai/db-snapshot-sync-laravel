<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Phattarachai\DbSnapshotSyncLaravel\Sanitizers\SanitizerFactory;

use function Laravel\Prompts\select;

class SedCommand extends Command
{
    protected $signature = 'snapshot:sed
        {name? : The snapshot filename on the snapshots disk}
        {--latest : Use the most recent snapshot}';

    protected $description = 'Strip dump-tool directives that reject on a local DB client so a snapshot re-imports cleanly.';

    public function handle(SanitizerFactory $factory): int
    {
        $disk = Storage::disk((string) config('db-snapshot-sync.disk'));

        $snapshots = $this->findSnapshots($disk);

        if ($snapshots->isEmpty()) {
            $this->info('No .sql or .sql.gz files found on the snapshots disk.');

            return self::SUCCESS;
        }

        $file = $this->resolveSnapshot($snapshots);

        if ($file === null) {
            $this->error('Snapshot not found.');

            return self::FAILURE;
        }

        $output = $factory->forDefaultConnection()->sanitize($disk, $file);

        $this->info('Sanitized: '.$file.($output === $file ? '' : " → {$output}"));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, string>
     */
    private function findSnapshots(Filesystem $disk): Collection
    {
        return collect($disk->files())
            ->filter(fn (string $file): bool => str_ends_with($file, '.sql') || str_ends_with($file, '.sql.gz'))
            ->reject(fn (string $file): bool => str_contains($file, '.sanitized.'))
            ->sortByDesc(fn (string $file): int => $disk->lastModified($file))
            ->values();
    }

    /**
     * @param  Collection<int, string>  $snapshots
     */
    private function resolveSnapshot(Collection $snapshots): ?string
    {
        if ($this->option('latest')) {
            return $snapshots->first();
        }

        $name = $this->argument('name');

        if (is_string($name) && $name !== '') {
            return $snapshots->first(fn (string $file): bool => in_array($file, [
                $name,
                "{$name}.sql",
                "{$name}.sql.gz",
            ], true));
        }

        return select(
            label: 'Which snapshot would you like to sanitize?',
            options: $snapshots->all(),
        );
    }
}
