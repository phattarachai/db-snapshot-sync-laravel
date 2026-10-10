<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Backup;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Phattarachai\DbSnapshotSyncLaravel\Events\SnapshotDrillCompleted;
use Phattarachai\DbSnapshotSyncLaravel\Support\DumpEngine;
use Phattarachai\DbSnapshotSyncLaravel\Support\PsqlLoader;
use RuntimeException;
use Spatie\DbSnapshots\Snapshot;
use Throwable;

/**
 * Proves the off-site copy restores: downloads the newest daily copy from a
 * target disk (not the local one), loads it into `<database>_restore_drill` on
 * the live connection's server, compares per-table row counts with the live
 * database, and drops the scratch database again whatever happened.
 *
 * Built to run on production — the data never leaves the box. The live
 * database is only ever read (row counts); every write goes to the scratch one.
 * The connection's user needs CREATE DATABASE (pgsql: the CREATEDB attribute;
 * MySQL: CREATE and DROP on `<database>_restore_drill`).
 */
final class RestoreDrill
{
    public const string SCRATCH_SUFFIX = '_restore_drill';

    private const string SCRATCH_CONNECTION = 'db_snapshot_sync_restore_drill';

    public function __construct(private readonly PsqlLoader $psql) {}

    /**
     * @param  (Closure(string): void)|null  $note  progress lines, e.g. for console output
     */
    public function run(?string $disk = null, ?string $connection = null, ?Closure $note = null, ?BackupConfig $config = null): DrillResult
    {
        $config ??= BackupConfig::fromConfig();
        $note ??= static function (string $line): void {};
        $disk ??= $config->disks[0] ?? throw new RuntimeException('No backup target disks are configured (db-snapshot-sync.backup.disks); pass --disk.');
        $connection ??= DB::getDefaultConnection();

        $live = DB::connection($connection);
        $scratch = $live->getDatabaseName().self::SCRATCH_SUFFIX;
        $result = new DrillResult($disk, $connection, $scratch);

        $started = hrtime(true);
        $download = null;
        $touchedScratch = false;

        try {
            $engine = DumpEngine::forDriver($live->getDriverName())
                ?? throw new RuntimeException("Connection [{$connection}] uses the {$live->getDriverName()} driver; the drill supports pgsql and mysql/mariadb.");

            $download = $this->download($disk, $config, $result, $note);

            $found = DumpEngine::detect($download);

            if ($found !== null && $found !== $engine) {
                throw new RuntimeException("{$result->snapshot} is a ".DumpEngine::label($found)." dump, but connection [{$connection}] uses the {$live->getDriverName()} driver.");
            }

            $touchedScratch = true;
            $this->createScratch($live, $scratch, $note);
            $this->load($download, $live, $scratch, $engine, $result, $note);
            $this->compare($live, $result);
        } catch (Throwable $e) {
            $result->error = $e->getMessage();
        } finally {
            DB::purge(self::SCRATCH_CONNECTION);

            if ($touchedScratch) {
                $this->dropScratch($live, $scratch, $result, $note);
            }

            if ($download !== null && is_file($download)) {
                unlink($download);
            }

            $result->totalSeconds = self::since($started);
        }

        event(new SnapshotDrillCompleted($result));

        return $result;
    }

    /**
     * Stream the target's newest daily copy to a local working file.
     *
     * @param  Closure(string): void  $note
     */
    private function download(string $disk, BackupConfig $config, DrillResult $result, Closure $note): string
    {
        $target = Storage::disk($disk);
        $newest = Snapshots::on($target, $config->dailyDir())->first()
            ?? throw new RuntimeException("[{$disk}] holds no daily copy under {$config->dailyDir()}/ to drill.");

        $result->snapshot = $newest->path;
        $note("Downloading {$newest->path} from [{$disk}] ({$newest->size} bytes)...");

        $dir = storage_path('app/db-snapshot-sync-drill');

        if (! is_dir($dir) && ! mkdir($dir, 0o700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create the drill's working directory {$dir}.");
        }

        $path = $dir.'/'.$newest->name();
        $started = hrtime(true);
        $in = $target->readStream($newest->path);
        $out = fopen($path, 'wb');

        try {
            if (! is_resource($in) || $out === false) {
                throw new RuntimeException("Could not stream {$newest->path} from [{$disk}] to {$path}.");
            }

            stream_copy_to_stream($in, $out);
        } finally {
            is_resource($in) && fclose($in);
            is_resource($out) && fclose($out);
        }

        clearstatcache(true, $path);

        if (filesize($path) !== $newest->size) {
            throw new RuntimeException("Downloaded {$newest->path} is ".filesize($path)." bytes, expected {$newest->size}.");
        }

        $result->downloadSeconds = self::since($started);

        return $path;
    }

    /**
     * @param  Closure(string): void  $note
     */
    private function createScratch(Connection $live, string $scratch, Closure $note): void
    {
        $note("Creating scratch database {$scratch}...");

        $name = $live->getQueryGrammar()->wrap($scratch);

        // A run killed before its cleanup leaves the scratch database behind.
        $live->statement("DROP DATABASE IF EXISTS {$name}");
        $live->statement("CREATE DATABASE {$name}");

        // A `url` would win over `database`, so resolve it into plain keys first.
        $config = (new ConfigurationUrlParser)->parseConfiguration((array) config("database.connections.{$live->getName()}"));
        unset($config['url']);

        config(['database.connections.'.self::SCRATCH_CONNECTION => array_merge($config, ['database' => $scratch])]);

        $resolved = DB::connection(self::SCRATCH_CONNECTION)->getDatabaseName();

        if ($resolved !== $scratch) {
            throw new RuntimeException("The scratch connection resolved to database [{$resolved}] instead of [{$scratch}]; refusing to load.");
        }
    }

    /**
     * @param  Closure(string): void  $note
     */
    private function load(string $path, Connection $live, string $scratch, string $engine, DrillResult $result, Closure $note): void
    {
        $note("Loading into {$scratch}...");
        $started = hrtime(true);

        $engine === DumpEngine::PGSQL
            ? $this->psql->load($path, self::SCRATCH_CONNECTION, dropTables: false)
            : $this->loadMySql($path, $live);

        $result->loadSeconds = self::since($started);
    }

    /**
     * spatie's streamed loader, pointed at the scratch connection. It makes that
     * the default connection and never switches back, so put the caller's back.
     */
    private function loadMySql(string $path, Connection $live): void
    {
        $disk = Storage::build(['driver' => 'local', 'root' => dirname($path)]);

        if (! $disk instanceof FilesystemAdapter) {
            throw new RuntimeException('Could not open the drill\'s working directory as a local disk.');
        }

        $default = DB::getDefaultConnection();

        try {
            (new Snapshot($disk, basename($path)))->useStream()->load(self::SCRATCH_CONNECTION, dropTables: false);
        } finally {
            DB::setDefaultConnection($default);
        }
    }

    private function compare(Connection $live, DrillResult $result): void
    {
        $liveCounts = $this->rowCounts($live);
        $restoredCounts = $this->rowCounts(DB::connection(self::SCRATCH_CONNECTION));
        $excluded = array_map(strval(...), (array) config('db-snapshot-sync.dump.exclude_table_data', []));

        foreach ($liveCounts as $table => $count) {
            $restored = $restoredCounts[$table] ?? null;
            $result->tables[] = ['table' => $table, 'live' => $count, 'restored' => $restored];

            if ($restored === null) {
                $result->missing[] = $table;
            } elseif ($restored === 0 && $count > 0 && ! in_array(Str::afterLast($table, '.'), $excluded, true)) {
                $result->emptied[] = $table;
            }
        }

        foreach (array_diff_key($restoredCounts, $liveCounts) as $table => $count) {
            $result->tables[] = ['table' => (string) $table, 'live' => null, 'restored' => $count];
        }
    }

    /**
     * Exact row counts per table, keyed by a name that matches across the live and
     * scratch databases (schema-qualified on pgsql, bare on MySQL, whose schema is
     * the database itself).
     *
     * @return array<string, int>
     */
    private function rowCounts(Connection $db): array
    {
        $schema = $db->getSchemaBuilder();
        $counts = [];

        foreach ($schema->getTables($schema->getCurrentSchemaListing()) as $table) {
            $name = $db->getDriverName() === 'pgsql' ? $table['schema_qualified_name'] : $table['name'];
            $counts[$name] = $db->table($name)->count();
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param  Closure(string): void  $note
     */
    private function dropScratch(Connection $live, string $scratch, DrillResult $result, Closure $note): void
    {
        try {
            $live->statement('DROP DATABASE IF EXISTS '.$live->getQueryGrammar()->wrap($scratch));
            $note("Dropped scratch database {$scratch}.");
        } catch (Throwable $e) {
            $result->error ??= "Could not drop the scratch database {$scratch}: {$e->getMessage()}";
            $note("Could not drop the scratch database {$scratch}: {$e->getMessage()}");
        }
    }

    private static function since(int|float $started): float
    {
        return round((hrtime(true) - $started) / 1e9, 2);
    }
}
