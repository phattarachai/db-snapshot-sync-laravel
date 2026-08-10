<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Http\Controllers;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SnapshotController
{
    /**
     * @return array{snapshots: Collection<int, array{name: string, size: int, last_modified: string}>}
     */
    public function index(): array
    {
        $disk = $this->disk();

        return [
            'snapshots' => $this->candidates($disk)
                ->values()
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
    public function store(): array
    {
        set_time_limit(0);

        $name = 'sync_'.Carbon::now()->format('Y-m-d_H-i-s');

        Artisan::call('snapshot:create', ['name' => $name, '--compress' => true]);

        $disk = $this->disk();
        $file = "{$name}.sql.gz";

        abort_unless($disk->exists($file), 500, 'snapshot:create finished but the expected file is missing.');

        return ['name' => $file, 'size' => $disk->size($file)];
    }

    public function latest(): StreamedResponse
    {
        $disk = $this->disk();

        $latest = $this->candidates($disk)->first();

        abort_if($latest === null, 404, 'No snapshot available.');

        return $disk->download($latest);
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('db-snapshot-sync.disk'));
    }

    /**
     * @return Collection<int, string>
     */
    private function candidates(Filesystem $disk): Collection
    {
        $reject = (array) config('db-snapshot-sync.api.reject', []);

        return collect($disk->files())
            ->filter(fn (string $file): bool => str_ends_with($file, '.sql.gz') || str_ends_with($file, '.sql'))
            ->reject(fn (string $file): bool => array_any($reject, fn (string $fragment): bool => str_contains($file, $fragment)))
            ->sortByDesc(fn (string $file): int => $disk->lastModified($file))
            ->values();
    }
}
