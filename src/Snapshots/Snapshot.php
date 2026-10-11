<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Snapshots;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\FilesystemOperator;
use Override;
use Phattarachai\DbSnapshotSyncLaravel\Compression\Compression;
use Phattarachai\DbSnapshotSyncLaravel\Compression\SnapshotStream;
use Spatie\DbSnapshots\Snapshot as SpatieSnapshot;

/**
 * spatie's snapshot, also loading a .sql.zst. Plain and gzip snapshots take
 * spatie's own code path unchanged.
 */
class Snapshot extends SpatieSnapshot
{
    public function __construct(FilesystemAdapter $disk, string $fileName)
    {
        parent::__construct($disk, $fileName);

        if (str_ends_with($fileName, '.'.Compression::Zstd->extension())) {
            $this->compressionExtension = Compression::Zstd->extension();
            $this->name = pathinfo(pathinfo($fileName, PATHINFO_FILENAME), PATHINFO_FILENAME);
        }
    }

    #[Override]
    protected function loadAsync(?string $connectionName = null): void
    {
        if (! $this->isZstd()) {
            parent::loadAsync($connectionName);

            return;
        }

        $contents = SnapshotStream::contents($this->disk->path($this->fileName));

        DB::connection($connectionName)->unprepared($this->filterPsqlMetaCommands($contents));
    }

    /**
     * Runs spatie's own streamed statement splitter, fed the decompressed SQL:
     * its non-gzip branch reads $this->disk->readStream(), so for the duration
     * the disk hands back the zstd pipe.
     */
    #[Override]
    protected function loadStream(?string $connectionName = null): void
    {
        if (! $this->isZstd()) {
            parent::loadStream($connectionName);

            return;
        }

        $disk = $this->disk;
        $stream = SnapshotStream::read($disk->path($this->fileName));

        $this->disk = new class($disk->getDriver(), $disk->getAdapter(), $disk->getConfig(), $stream->handle) extends FilesystemAdapter
        {
            /**
             * @param  array<string, mixed>  $config
             * @param  resource  $sql
             */
            public function __construct(FilesystemOperator $driver, FlysystemAdapter $adapter, array $config, private readonly mixed $sql)
            {
                parent::__construct($driver, $adapter, $config);
            }

            /**
             * @return resource
             */
            #[Override]
            public function readStream($path)
            {
                return $this->sql;
            }
        };

        try {
            parent::loadStream($connectionName);
            $stream->close();
        } finally {
            $this->disk = $disk;
        }
    }

    private function isZstd(): bool
    {
        return $this->compressionExtension === Compression::Zstd->extension();
    }
}
