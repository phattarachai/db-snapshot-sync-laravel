<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Http\Controllers;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Phattarachai\DbSnapshotSyncLaravel\Support\SnapshotCandidates;
use Phattarachai\DbSnapshotSyncLaravel\Support\SyncSnapshotBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The deprecated HTTP snapshot API. SSH sources (`snapshot:export`) replace it;
 * it is removed in 2.0.
 */
class SnapshotController
{
    /**
     * @return array{snapshots: Collection<int, array{name: string, size: int, last_modified: string}>}
     */
    public function index(): array
    {
        $disk = $this->disk();

        return [
            'snapshots' => SnapshotCandidates::on($disk)
                ->map(fn (string $file): array => [
                    'name' => $file,
                    'size' => $disk->size($file),
                    'last_modified' => Carbon::createFromTimestamp($disk->lastModified($file))->toIso8601String(),
                ]),
        ];
    }

    /**
     * @return array{name: string, size: int}
     */
    public function store(SyncSnapshotBuilder $builder): array
    {
        set_time_limit(0);

        $file = $builder->build();

        return ['name' => $file, 'size' => $this->disk()->size($file)];
    }

    public function latest(): StreamedResponse
    {
        $disk = $this->disk();

        $latest = SnapshotCandidates::newest($disk);

        abort_if($latest === null, 404, 'No snapshot available.');

        return $disk->download($latest);
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('db-snapshot-sync.disk'));
    }
}
