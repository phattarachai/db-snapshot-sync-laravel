<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Console\Prohibitable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Pulse\Facades\Pulse;
use Phattarachai\DbSnapshotSyncLaravel\Compression\Compression;
use Phattarachai\DbSnapshotSyncLaravel\Sanitizers\Sanitizer;
use Phattarachai\DbSnapshotSyncLaravel\Sanitizers\SanitizerFactory;
use Phattarachai\DbSnapshotSyncLaravel\Support\CaBundle;
use Phattarachai\DbSnapshotSyncLaravel\Support\DumpEngine;
use Phattarachai\DbSnapshotSyncLaravel\Support\SnapshotRestorer;
use Phattarachai\DbSnapshotSyncLaravel\Transfer\SshPuller;
use Phattarachai\DbSnapshotSyncLaravel\Transfer\SshSource;
use Phattarachai\DbSnapshotSyncLaravel\Transfer\TransferFailed;

class SyncCommand extends Command
{
    use Prohibitable;

    protected $signature = 'snapshot:sync
        {--fresh : Trigger a brand-new snapshot on the source before downloading (slow)}
        {--source=production : Source key from config db-snapshot-sync.sources (prod is an alias)}
        {--connection= : Local connection to sanitize for and load into (default: the source\'s "connection", else the default connection)}
        {--no-load : Download + sanitize only, skip loading into the local DB}
        {--keep-raw : Keep the raw download when the sanitizer writes a separate file}';

    protected $description = 'Pull the latest DB snapshot from a source, sanitize it, and load it into the local DB.';

    public function handle(SanitizerFactory $factory): int
    {
        if ($this->isProhibited()) {
            return self::FAILURE;
        }

        if (class_exists(Pulse::class)) {
            Pulse::stopRecording();
        }

        $refusal = $this->environmentRefusal();

        if ($refusal !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        try {
            $source = $this->resolveSource();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($source === null) {
            $this->error("Unknown or unconfigured --source={$this->option('source')}.");

            return self::FAILURE;
        }

        $connection = $this->targetConnection($source['connection']);

        try {
            $driver = $factory->driverOf($connection);
            $sanitizer = $factory->for($driver);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($source['ssh'] instanceof SshSource) {
            $this->info("Source: {$source['ssh']->label()}");
            $this->info("Target connection: {$connection} ({$driver})");

            try {
                $downloaded = $this->pullOverSsh($source['ssh']);
            } catch (TransferFailed $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        } else {
            $client = $this->httpClient((string) $source['url']);

            if (! $client instanceof PendingRequest) {
                return self::FAILURE;
            }

            $this->info("Source: {$source['url']}");
            $this->info("Target connection: {$connection} ({$driver})");
            $this->warn('HTTP sources, the internal API and INTERNAL_API_TOKEN are deprecated and removed in 2.0. Give this source an ssh host and path instead (README: "SSH sources").');

            if ($this->option('fresh')) {
                $this->triggerFreshSnapshot($client);
            }

            $downloaded = $this->downloadLatest($client);
        }

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
     * A non-empty allow-list decides alone; otherwise every environment but the
     * denied ones may sync.
     */
    private function environmentRefusal(): ?string
    {
        $allowed = array_values(array_filter((array) config('db-snapshot-sync.sync.allowed_environments', [])));

        if ($allowed !== []) {
            return app()->environment($allowed) ? null : 'snapshot:sync may only run in: '.implode(', ', $allowed).'.';
        }

        $denied = array_values(array_filter((array) config('db-snapshot-sync.sync.denied_environments', ['production'])));

        return $denied !== [] && app()->environment($denied)
            ? 'snapshot:sync may not run in: '.implode(', ', $denied).'.'
            : null;
    }

    /**
     * A source is ['ssh' => alias, 'path' => app dir, 'run_as' => user, 'connection' => ...]; an ssh
     * host wins over a 'url'. A plain string, or an entry with only a 'url', is a deprecated HTTP source.
     *
     * @return array{ssh: ?SshSource, url: ?string, connection: ?string}|null
     *
     * @throws InvalidArgumentException when an ssh source has no path
     */
    private function resolveSource(): ?array
    {
        $key = $this->option('source') === 'prod' ? 'production' : (string) $this->option('source');
        $entry = config("db-snapshot-sync.sources.{$key}");
        $connection = is_array($entry) ? ($entry['connection'] ?? null) : null;
        $connection = is_string($connection) && $connection !== '' ? $connection : null;

        if (is_array($entry) && is_string($entry['ssh'] ?? null) && $entry['ssh'] !== '') {
            return ['ssh' => SshSource::fromConfig($key, $entry), 'url' => null, 'connection' => $connection];
        }

        $url = is_array($entry) ? ($entry['url'] ?? null) : $entry;

        if (! is_string($url) || $url === '') {
            return null;
        }

        return ['ssh' => null, 'url' => $url, 'connection' => $connection];
    }

    /**
     * Run snapshot:export on the box and write what it streams into the snapshots disk.
     */
    private function pullOverSsh(SshSource $source): string
    {
        $this->info($this->option('fresh')
            ? 'Building a fresh snapshot on the source and streaming it (may take several minutes)...'
            : 'Streaming the latest snapshot...');

        $rawName = $this->rawDownloadName();
        $disk = Storage::disk((string) config('db-snapshot-sync.disk'));

        $result = app(SshPuller::class)->pull(
            $source,
            ['snapshot:export', ...($this->option('fresh') ? ['--fresh'] : [])],
            $disk->path($rawName),
            fn (string $line) => $this->line("  <fg=gray>source:</> {$line}"),
        );

        $this->info("Received {$result->name} (".number_format($result->size).' bytes, sha256 verified).');

        return $this->nameByContent($rawName);
    }

    private function httpClient(string $baseUrl): ?PendingRequest
    {
        $token = config('db-snapshot-sync.token');

        if (! is_string($token) || $token === '') {
            $this->error('INTERNAL_API_TOKEN is not set in your local .env.');

            return null;
        }

        $caBundle = config('db-snapshot-sync.http.ca_bundle');

        return Http::acceptJson()
            ->baseUrl($baseUrl)
            ->withToken($token)
            ->withOptions(['verify' => CaBundle::resolve(is_string($caBundle) ? $caBundle : null)])
            ->timeout(0);
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
     * the load drops every table on the target first and only then fails on
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

        $rawName = $this->rawDownloadName();

        $client->withOptions(['sink' => Storage::disk((string) config('db-snapshot-sync.disk'))->path($rawName)])
            ->get('/'.$this->apiPath('snapshots/latest'))
            ->throw();

        return $this->nameByContent($rawName);
    }

    /**
     * `sync_<timestamp>.download` on the snapshots disk, its directory created.
     */
    private function rawDownloadName(): string
    {
        $rawName = 'sync_'.now()->format('Y-m-d_H-i-s').'.download';
        $rawPath = Storage::disk((string) config('db-snapshot-sync.disk'))->path($rawName);

        if (! is_dir(dirname($rawPath))) {
            mkdir(dirname($rawPath), 0o755, true);
        }

        return $rawName;
    }

    /**
     * The source may send a gzip (.sql.gz), zstd (.sql.zst) or plain (.sql)
     * dump. Name the local file by the payload's real type — sniffed from its
     * magic bytes — so the sanitizer and the loader agree with the content
     * instead of a guessed extension.
     */
    private function nameByContent(string $rawName): string
    {
        $disk = Storage::disk((string) config('db-snapshot-sync.disk'));
        $localName = Str::beforeLast($rawName, '.download').Compression::sniff($disk->path($rawName))->suffix();
        $disk->move($rawName, $localName);

        return $localName;
    }

    private function sanitize(Sanitizer $sanitizer, string $downloaded): string
    {
        $this->info('Sanitizing...');

        $disk = Storage::disk((string) config('db-snapshot-sync.disk'));

        return $sanitizer->sanitize($disk, $downloaded);
    }

    private function loadIntoLocalDb(string $name, string $connection, string $driver): void
    {
        $this->info("Loading into local DB [{$connection}] ({$driver})...");

        $path = Storage::disk((string) config('db-snapshot-sync.disk'))->path($name);
        $default = DB::getDefaultConnection();

        try {
            app(SnapshotRestorer::class)->restore($path, $connection, (bool) config('db-snapshot-sync.load.drop-tables', true));
        } finally {
            // A pg_dump leaves search_path = '' on a pooled session: start the
            // caller (e.g. a command running snapshot:sync in-process) afresh.
            DB::setDefaultConnection($default);
            DB::purge($connection);
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
