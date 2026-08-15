<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Http\Controllers;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Phattarachai\DbSnapshotSyncLaravel\Support\DumpOptions;
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

        $this->applySyncDumpOptions();

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

    /**
     * Append `--exclude-table-data` flags for framework/transient tables to the
     * default connection's dump options, so the sync snapshot carries their schema
     * but not their (large, disposable) data. Scoped to this request — the deploy's
     * own rollback snapshots read the unmodified config.
     */
    /**
     * Merge the sync-only dump options — framework table-data excludes and
     * multi-row INSERT batching — into the default connection's dump flags, on
     * top of the connection's own. Scoped to this request, so a project's rollback
     * snapshots and committed baseline (which want single-row INSERTs for clean
     * diffs) read the unmodified config.
     */
    private function applySyncDumpOptions(): void
    {
        $tables = array_values(array_filter((array) config('db-snapshot-sync.dump.exclude_table_data', [])));
        $rowsPerInsert = config('db-snapshot-sync.dump.rows_per_insert');

        $connection = config('database.default');
        $key = "database.connections.{$connection}.dump.addExtraOption";

        $options = DumpOptions::withExcludedTableData((string) config($key, ''), $tables);
        $options = DumpOptions::withRowsPerInsert($options, $rowsPerInsert === null ? null : (int) $rowsPerInsert);

        config([$key => $options]);
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
