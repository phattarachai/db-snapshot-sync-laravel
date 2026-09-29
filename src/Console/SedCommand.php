<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Phattarachai\DbSnapshotSyncLaravel\Sanitizers\SanitizerFactory;
use Phattarachai\DbSnapshotSyncLaravel\Support\DumpEngine;

use function Laravel\Prompts\select;

class SedCommand extends Command
{
    protected $signature = 'snapshot:sed
        {name? : The snapshot filename on the snapshots disk}
        {--latest : Use the most recent snapshot}
        {--connection= : Sanitize for this connection\'s driver (default: the dump\'s own engine, else the default connection)}
        {--driver= : Sanitize for this driver (mysql, mariadb or pgsql) instead of a connection\'s}';

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

        $engine = DumpEngine::detect($disk->path($file));

        try {
            $driver = $this->resolveDriver($factory, $engine);
            $sanitizer = $factory->for($driver);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($engine !== null && $engine !== DumpEngine::forDriver($driver)) {
            $this->error("{$file} is a ".DumpEngine::label($engine)." dump, but the {$driver} sanitizer was requested. Nothing was changed.");

            return self::FAILURE;
        }

        $output = $sanitizer->sanitize($disk, $file);

        $this->info('Sanitized: '.$file.($output === $file ? '' : " → {$output}"));

        return self::SUCCESS;
    }

    /**
     * An explicit --driver or --connection wins. Without either, a snapshot is
     * sanitized for the engine that wrote it — a MySQL dump on an app whose
     * default connection is pgsql still gets the MySQL rules — falling back to
     * the default connection's driver when the header names neither engine.
     */
    private function resolveDriver(SanitizerFactory $factory, ?string $engine): string
    {
        $driver = $this->option('driver');
        $connection = $this->option('connection');

        return match (true) {
            is_string($driver) && $driver !== '' && is_string($connection) && $connection !== '' => throw new InvalidArgumentException('Pass either --driver or --connection, not both.'),
            is_string($driver) && $driver !== '' => $driver,
            is_string($connection) && $connection !== '' => $factory->driverOf($connection),
            default => $engine ?? $factory->driverOf((string) config('database.default')),
        };
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
