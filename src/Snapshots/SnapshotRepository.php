<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Snapshots;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use Override;
use Phattarachai\DbSnapshotSyncLaravel\Compression\Compression;
use Spatie\DbSnapshots\SnapshotRepository as SpatieSnapshotRepository;

/**
 * spatie's repository, also listing .sql.zst — so snapshot:load, :list, :delete
 * and :cleanup see zstd snapshots.
 */
class SnapshotRepository extends SpatieSnapshotRepository
{
    public function __construct(private readonly FilesystemAdapter $snapshots)
    {
        parent::__construct($snapshots);
    }

    /**
     * @return Collection<int, Snapshot>
     */
    #[Override]
    public function getAll(): Collection
    {
        return collect($this->snapshots->allFiles())
            ->filter(Compression::isSnapshotName(...))
            ->map(fn (string $fileName): Snapshot => new Snapshot($this->snapshots, $fileName))
            ->sortByDesc(fn (Snapshot $snapshot): string => $snapshot->createdAt()->toDateTimeString());
    }
}
