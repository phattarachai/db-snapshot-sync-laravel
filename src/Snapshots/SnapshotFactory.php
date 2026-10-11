<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Snapshots;

use Illuminate\Filesystem\FilesystemAdapter;
use Override;
use Phattarachai\DbSnapshotSyncLaravel\Compression\Compression;
use Spatie\DbSnapshots\Events\CreatedSnapshot;
use Spatie\DbSnapshots\Events\CreatingSnapshot;
use Spatie\DbSnapshots\Snapshot as SpatieSnapshot;
use Spatie\DbSnapshots\SnapshotFactory as SpatieSnapshotFactory;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * spatie's factory with the compressor taken from
 * db-snapshot-sync.compression.format instead of hardcoded gzip. With the
 * default (gzip) it writes exactly what spatie's does.
 */
class SnapshotFactory extends SpatieSnapshotFactory
{
    /**
     * @param  array<int, string>|null  $tables
     * @param  array<int, string>|null  $exclude
     * @param  array<int, string>  $extraOptions
     */
    #[Override]
    public function create(string $snapshotName, string $diskName, string $connectionName, bool $compress = false, ?array $tables = null, ?array $exclude = null, array $extraOptions = []): SpatieSnapshot
    {
        $disk = $this->getDisk($diskName);
        $format = $compress ? Compression::configured() : Compression::None;

        $fileName = pathinfo($snapshotName.'.sql', PATHINFO_BASENAME).($format === Compression::None ? '' : '.'.$format->extension());

        event(new CreatingSnapshot($fileName, $disk, $connectionName, $tables, $exclude, $extraOptions));

        $this->dump($connectionName, $fileName, $disk, $format, $tables, $exclude, $extraOptions);

        $snapshot = new Snapshot($disk, $fileName);

        event(new CreatedSnapshot($snapshot));

        return $snapshot;
    }

    /**
     * @param  array<int, string>|null  $tables
     * @param  array<int, string>|null  $exclude
     * @param  array<int, string>  $extraOptions
     */
    private function dump(string $connectionName, string $fileName, FilesystemAdapter $disk, Compression $format, ?array $tables, ?array $exclude, array $extraOptions): void
    {
        $directory = new TemporaryDirectory(config('db-snapshots.temporary_directory_path'))->create();

        try {
            $dumper = $this->getDbDumper($connectionName);
            $compressor = $format->compressor();

            if ($compressor !== null) {
                $dumper->useCompressor($compressor);
            }

            if (is_array($tables)) {
                $dumper->includeTables($tables);
            }

            if (is_array($exclude)) {
                $dumper->excludeTables($exclude);
            }

            foreach ($extraOptions as $extraOption) {
                $dumper->addExtraOption($extraOption);
            }

            $dumpPath = $directory->path($fileName);
            $dumper->dumpToFile($dumpPath);

            $file = fopen($dumpPath, 'r');
            $disk->put($fileName, $file);

            if (is_resource($file)) {
                fclose($file);
            }
        } finally {
            $directory->delete();
        }
    }
}
