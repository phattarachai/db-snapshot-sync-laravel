<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Http\Controllers;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Phattarachai\DbSnapshotSyncLaravel\Support\DumpOptions;
use Phattarachai\DbSnapshotSyncLaravel\Support\MySqlTableDataExclusion;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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
    public function store(MySqlTableDataExclusion $mysql): array
    {
        set_time_limit(0);

        $connection = (string) config('database.default');
        $dump = config("database.connections.{$connection}.dump");

        $schemaOnly = $this->applySyncDumpOptions($connection, $mysql);

        $name = 'sync_'.Carbon::now()->format('Y-m-d_H-i-s');

        try {
            Artisan::call('snapshot:create', ['name' => $name, '--compress' => true]);
        } finally {
            config(["database.connections.{$connection}.dump" => $dump]);
        }

        $disk = $this->disk();
        $file = "{$name}.sql.gz";

        abort_unless($disk->exists($file), 500, 'snapshot:create finished but the expected file is missing.');

        try {
            if ($schemaOnly !== []) {
                $mysql->appendSchema($connection, $schemaOnly, $disk->path($file));
            }
        } catch (Throwable $e) {
            // Never leave a snapshot missing those tables for `latest` to serve.
            $disk->delete($file);

            throw $e;
        }

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
     * Merge the sync-only dump options — framework table-data excludes and
     * multi-row INSERT batching — into the connection's dump flags, on top of the
     * connection's own, for this snapshot only (store() restores them), so a
     * project's rollback snapshots and committed baseline (which want single-row
     * INSERTs for clean diffs) are untouched.
     *
     * pgsql excludes data in one pass with pg_dump flags. mysql/mariadb ignore the
     * existing excluded tables here and get their schema appended afterwards;
     * those tables are returned for that second pass.
     *
     * @return list<string>
     */
    private function applySyncDumpOptions(string $connection, MySqlTableDataExclusion $mysql): array
    {
        $tables = array_values(array_filter((array) config('db-snapshot-sync.dump.exclude_table_data', [])));
        $rowsPerInsert = config('db-snapshot-sync.dump.rows_per_insert');

        $driver = (string) config("database.connections.{$connection}.driver");
        $key = "database.connections.{$connection}.dump.addExtraOption";
        $existing = (string) config($key, '');

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $schemaOnly = $mysql->existing($connection, $tables);

            if ($schemaOnly !== []) {
                config([$key => DumpOptions::withIgnoredTables($existing, $mysql->database($connection), $schemaOnly)]);
            }

            return $schemaOnly;
        }

        $options = DumpOptions::forSync($driver, $existing, $tables, $rowsPerInsert === null ? null : (int) $rowsPerInsert);

        if ($options !== null) {
            config([$key => $options]);
        }

        return [];
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
