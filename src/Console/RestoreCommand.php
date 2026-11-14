<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Pulse\Facades\Pulse;
use Phattarachai\DbSnapshotSyncLaravel\Backup\SnapshotFile;
use Phattarachai\DbSnapshotSyncLaravel\Compression\Compression;
use Phattarachai\DbSnapshotSyncLaravel\Support\DumpEngine;
use Phattarachai\DbSnapshotSyncLaravel\Support\SnapshotCandidates;
use Phattarachai\DbSnapshotSyncLaravel\Support\SnapshotRestorer;
use Throwable;

/**
 * Loads a snapshot from the snapshots disk into a connection without the
 * failure modes of spatie's `snapshot:load`: the dump streams through psql (one
 * transaction, the old objects dropped inside it, so a failure leaves the
 * database as it was) or the mysql client, and is never read whole into PHP.
 */
class RestoreCommand extends Command
{
    protected $signature = 'snapshot:restore
        {name? : Snapshot name on the snapshots disk, with or without .sql / .sql.gz / .sql.zst}
        {--latest : Restore the newest snapshot instead of a named one}
        {--connection= : Connection to restore into (default: the default connection)}
        {--role= : PostgreSQL role to SET ROLE to first, so restored objects belong to it}
        {--keep-tables : Do not drop the existing objects first}
        {--force : Skip the confirmation (required without a terminal, and in a protected environment)}';

    protected $description = 'Restore a DB snapshot safely: streamed through psql in one transaction (or the mysql client), never read whole into memory.';

    public function handle(SnapshotRestorer $restorer): int
    {
        if (class_exists(Pulse::class)) {
            Pulse::stopRecording();
        }

        $refusal = $this->environmentRefusal();

        if ($refusal !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $disk = Storage::disk((string) config('db-snapshot-sync.disk'));
        $file = $this->snapshot($disk);

        if ($file === null) {
            return self::FAILURE;
        }

        $connection = (string) ($this->option('connection') ?: config('database.default'));
        $driver = (string) config("database.connections.{$connection}.driver");
        $engine = DumpEngine::detect($disk->path($file));

        if ($driver === '') {
            $this->error("Connection [{$connection}] is not configured.");

            return self::FAILURE;
        }

        if ($engine !== null && $engine !== DumpEngine::forDriver($driver)) {
            $this->error("{$file} is a ".DumpEngine::label($engine)." dump, but connection [{$connection}] uses the {$driver} driver. Nothing was restored.");

            return self::FAILURE;
        }

        $database = (string) config("database.connections.{$connection}.database");
        $dropTables = ! $this->option('keep-tables') && (bool) config('db-snapshot-sync.load.drop-tables', true);

        if (! $this->option('force') && ! $this->confirm('Replace '.($dropTables ? 'every table' : 'the tables')." in [{$connection}] ({$database}) with {$file}?")) {
            $this->line('Nothing was restored.');

            return self::FAILURE;
        }

        $this->info("Restoring {$file} into [{$connection}] ({$database})...");
        $started = microtime(true);

        try {
            $restorer->restore($disk->path($file), $connection, $dropTables, $this->option('role') ?: null);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            DB::purge($connection);
        }

        $this->info(sprintf('Restored in %.1f s. Run `php artisan migrate` if the code is newer than the snapshot.', microtime(true) - $started));

        return self::SUCCESS;
    }

    /**
     * Protected environments (`restore.denied_environments`, default production)
     * restore only with --force AND the environment listed in
     * `restore.allowed_environments`, a setting someone has to put on the box on
     * purpose.
     */
    private function environmentRefusal(): ?string
    {
        $denied = array_values(array_filter((array) config('db-snapshot-sync.restore.denied_environments', ['production'])));

        if ($denied === [] || ! app()->environment($denied)) {
            return null;
        }

        $allowed = array_values(array_filter((array) config('db-snapshot-sync.restore.allowed_environments', [])));
        $environment = (string) app()->environment();

        return $this->option('force') && $allowed !== [] && app()->environment($allowed)
            ? null
            : "snapshot:restore replaces the database, and [{$environment}] is protected. To restore here, list it in DB_SNAPSHOT_SYNC_RESTORE_ALLOWED_ENVIRONMENTS on this box and pass --force.";
    }

    private function snapshot(Filesystem $disk): ?string
    {
        $name = $this->argument('name');

        if (is_string($name) && $name !== '') {
            $file = collect($disk->files())->first(fn (string $file): bool => SnapshotFile::isSnapshot($file)
                && ($file === $name || Compression::stem($file) === Compression::stem($name)));

            if ($file === null) {
                $this->error("No snapshot named [{$name}] on disk [".config('db-snapshot-sync.disk').'].');
                $this->listNewest($disk);
            }

            return $file;
        }

        if (! $this->option('latest')) {
            $this->error('Name a snapshot, or pass --latest.');
            $this->listNewest($disk);

            return null;
        }

        $latest = $this->newest($disk)[0] ?? null;

        if ($latest === null) {
            $this->error('No snapshot on disk ['.config('db-snapshot-sync.disk').'].');
        }

        return $latest;
    }

    /**
     * Newest first by the time in the name, leaving out protected files (a committed baseline).
     *
     * @return list<string>
     */
    private function newest(Filesystem $disk): array
    {
        $protected = array_values(array_filter((array) config('db-snapshot-sync.backup.protect', [])));

        return SnapshotCandidates::on($disk)
            ->reject(fn (string $file): bool => in_array(Compression::stem(basename($file)), $protected, true))
            ->sortByDesc(fn (string $file): int => new SnapshotFile($file, 0, $disk->lastModified($file))->takenAt())
            ->values()
            ->all();
    }

    private function listNewest(Filesystem $disk): void
    {
        $newest = array_slice($this->newest($disk), 0, 5);

        if ($newest !== []) {
            $this->line('Newest: '.implode(', ', $newest));
        }
    }
}
