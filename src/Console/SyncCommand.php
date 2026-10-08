<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Laravel\Pulse\Facades\Pulse;
use Phattarachai\DbSnapshotSyncLaravel\Sanitizers\Sanitizer;
use Phattarachai\DbSnapshotSyncLaravel\Sanitizers\SanitizerFactory;
use Phattarachai\DbSnapshotSyncLaravel\Support\CaBundle;
use Phattarachai\DbSnapshotSyncLaravel\Support\DumpEngine;
use Phattarachai\DbSnapshotSyncLaravel\Support\PsqlLoader;
use RuntimeException;

class SyncCommand extends Command
{
    protected $signature = 'snapshot:sync
        {--fresh : Trigger a brand-new snapshot on the source before downloading (slow)}
        {--source=production : Source key from config db-snapshot-sync.sources (prod is an alias)}
        {--connection= : Local connection to sanitize for and load into (default: the source\'s "connection", else the default connection)}
        {--no-load : Download + sanitize only, skip loading into the local DB}
        {--keep-raw : Keep the raw download when the sanitizer writes a separate file}';

    protected $description = 'Pull the latest DB snapshot from a source, sanitize it, and load it into the local DB.';

    public function handle(SanitizerFactory $factory): int
    {
        if (class_exists(Pulse::class)) {
            Pulse::stopRecording();
        }

        $allowed = (array) config('db-snapshot-sync.sync.allowed_environments', ['local', 'development']);

        if (! app()->environment($allowed)) {
            $this->error('snapshot:sync may only run in: '.implode(', ', $allowed).'.');

            return self::FAILURE;
        }

        $source = $this->resolveSource();

        if ($source === null) {
            $this->error("Unknown or unconfigured --source={$this->option('source')}.");

            return self::FAILURE;
        }

        $baseUrl = $source['url'];
        $connection = $this->targetConnection($source['connection']);

        try {
            $driver = $factory->driverOf($connection);
            $sanitizer = $factory->for($driver);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $token = config('db-snapshot-sync.token');

        if (! is_string($token) || $token === '') {
            $this->error('INTERNAL_API_TOKEN is not set in your local .env.');

            return self::FAILURE;
        }

        $caBundle = config('db-snapshot-sync.http.ca_bundle');
        $verify = CaBundle::resolve(is_string($caBundle) ? $caBundle : null);

        $client = Http::acceptJson()
            ->baseUrl($baseUrl)
            ->withToken($token)
            ->withOptions(['verify' => $verify])
            ->timeout(0);

        $this->info("Source: {$baseUrl}");
        $this->info("Target connection: {$connection} ({$driver})");

        if ($this->option('fresh')) {
            $this->triggerFreshSnapshot($client);
        }

        $downloaded = $this->downloadLatest($client);

        if (! $this->engineMatches($downloaded, $connection, $driver)) {
            return self::FAILURE;
        }

        $loadable = $this->sanitize($sanitizer, $downloaded);

        if (! $this->option('no-load')) {
            $this->loadIntoLocalDb($loadable, $connection, $driver);
        }

        $this->cleanUpRaw($downloaded, $loadable);

        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * A source is either a plain base URL or ['url' => ..., 'connection' => ...].
     *
     * @return array{url: string, connection: ?string}|null
     */
    private function resolveSource(): ?array
    {
        $key = $this->option('source') === 'prod' ? 'production' : (string) $this->option('source');
        $entry = config("db-snapshot-sync.sources.{$key}");
        $url = is_array($entry) ? ($entry['url'] ?? null) : $entry;

        if (! is_string($url) || $url === '') {
            return null;
        }

        $connection = is_array($entry) ? ($entry['connection'] ?? null) : null;

        return [
            'url' => $url,
            'connection' => is_string($connection) && $connection !== '' ? $connection : null,
        ];
    }

    private function targetConnection(?string $sourceConnection): string
    {
        $option = $this->option('connection');

        if (is_string($option) && $option !== '') {
            return $option;
        }

        return $sourceConnection ?? (string) config('database.default');
    }

    /**
     * Refuse a dump written by the other engine before it is sanitized or loaded:
     * snapshot:load drops every table on the target first and only then fails on
     * the foreign SQL. A dump whose header names neither engine is let through.
     */
    private function engineMatches(string $downloaded, string $connection, string $driver): bool
    {
        $disk = Storage::disk((string) config('db-snapshot-sync.disk'));
        $engine = DumpEngine::detect($disk->path($downloaded));

        if ($engine === null || $engine === DumpEngine::forDriver($driver)) {
            return true;
        }

        $this->error(
            "{$downloaded} is a ".DumpEngine::label($engine)." dump, but connection [{$connection}] uses the {$driver} driver. "
            .'Nothing was sanitized or loaded. Pass --connection=<a matching connection>, or set the source\'s "connection" in config db-snapshot-sync.sources.'
        );

        return false;
    }

    private function triggerFreshSnapshot(PendingRequest $client): void
    {
        $this->info('Requesting fresh snapshot on source (may take several minutes)...');
        $client->post('/'.$this->apiPath('snapshots'))->throw();
    }

    private function downloadLatest(PendingRequest $client): string
    {
        $this->info('Downloading latest snapshot...');

        $disk = Storage::disk((string) config('db-snapshot-sync.disk'));
        $stem = 'sync_'.now()->format('Y-m-d_H-i-s');
        $rawName = $stem.'.download';
        $rawPath = $disk->path($rawName);

        if (! is_dir(dirname($rawPath))) {
            mkdir(dirname($rawPath), 0o755, true);
        }

        $client->withOptions(['sink' => $rawPath])
            ->get('/'.$this->apiPath('snapshots/latest'))
            ->throw();

        // The source may serve either a compressed (.sql.gz) or a plain (.sql)
        // dump. Name the local file by the payload's real type — sniffed from the
        // gzip magic bytes — so the sanitizer and snapshot:load agree with the
        // content instead of a guessed extension.
        $localName = $stem.($this->isGzip($rawPath) ? '.sql.gz' : '.sql');
        $disk->move($rawName, $localName);

        return $localName;
    }

    private function isGzip(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $magic = (string) fread($handle, 2);
        fclose($handle);

        return $magic === "\x1f\x8b";
    }

    private function sanitize(Sanitizer $sanitizer, string $downloaded): string
    {
        $this->info('Sanitizing...');

        $disk = Storage::disk((string) config('db-snapshot-sync.disk'));

        return $sanitizer->sanitize($disk, $downloaded);
    }

    private function loadIntoLocalDb(string $name, string $connection, string $driver): void
    {
        $this->info("Loading into local DB [{$connection}]...");

        $flags = (array) config('db-snapshot-sync.load', []);
        $default = DB::getDefaultConnection();

        try {
            $driver === 'pgsql'
                ? $this->loadWithPsql($name, $connection, (bool) ($flags['drop-tables'] ?? true))
                : $this->loadWithSnapshotLoad($name, $connection, $flags);
        } finally {
            // spatie's Snapshot::load() makes the target the default connection and
            // never switches back, and a pg_dump leaves search_path = '' on the pooled
            // connection. Restore both so a caller running snapshot:sync in-process
            // (e.g. from another command) keeps its own default and a clean session.
            DB::setDefaultConnection($default);
            DB::purge($connection);
        }
    }

    /**
     * pg_dump output goes through psql, not spatie's PHP statement splitter, which
     * mis-reads a backslash before a quote and silently drops the rest of the dump.
     */
    private function loadWithPsql(string $name, string $connection, bool $dropTables): void
    {
        $path = Storage::disk((string) config('db-snapshot-sync.disk'))->path($name);

        app(PsqlLoader::class)->load($path, $connection, $dropTables);
    }

    /**
     * @param  array<array-key, mixed>  $flags
     */
    private function loadWithSnapshotLoad(string $name, string $connection, array $flags): void
    {
        $arguments = [
            'name' => preg_replace('/\.sql(\.gz)?$/', '', $name),
            '--connection' => $connection,
        ];

        foreach ($flags as $flag => $enabled) {
            if ($enabled) {
                $arguments["--{$flag}"] = true;
            }
        }

        if ($this->call('snapshot:load', $arguments) !== self::SUCCESS) {
            throw new RuntimeException('snapshot:load failed.');
        }
    }

    private function cleanUpRaw(string $downloaded, string $loadable): void
    {
        if ($loadable === $downloaded || $this->option('keep-raw')) {
            return;
        }

        Storage::disk((string) config('db-snapshot-sync.disk'))->delete($downloaded);
        $this->line("Cleaned up raw download: {$downloaded}");
    }

    private function apiPath(string $suffix): string
    {
        return trim((string) config('db-snapshot-sync.api.prefix', 'internal'), '/').'/'.$suffix;
    }
}
