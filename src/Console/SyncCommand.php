<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Pulse\Facades\Pulse;
use Phattarachai\DbSnapshotSyncLaravel\Sanitizers\SanitizerFactory;
use RuntimeException;

class SyncCommand extends Command
{
    protected $signature = 'snapshot:sync
        {--fresh : Trigger a brand-new snapshot on the source before downloading (slow)}
        {--source=production : Source key from config db-snapshot-sync.sources (prod is an alias)}
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

        $baseUrl = $this->resolveSource();

        if ($baseUrl === null) {
            $this->error("Unknown or unconfigured --source={$this->option('source')}.");

            return self::FAILURE;
        }

        $token = config('db-snapshot-sync.token');

        if (! is_string($token) || $token === '') {
            $this->error('INTERNAL_API_TOKEN is not set in your local .env.');

            return self::FAILURE;
        }

        $client = Http::acceptJson()->baseUrl($baseUrl)->withToken($token)->timeout(0);

        $this->info("Source: {$baseUrl}");

        if ($this->option('fresh')) {
            $this->triggerFreshSnapshot($client);
        }

        $downloaded = $this->downloadLatest($client);
        $loadable = $this->sanitize($factory, $downloaded);

        if (! $this->option('no-load')) {
            $this->loadIntoLocalDb($loadable);
        }

        $this->cleanUpRaw($downloaded, $loadable);

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function resolveSource(): ?string
    {
        $key = $this->option('source') === 'prod' ? 'production' : (string) $this->option('source');
        $url = config("db-snapshot-sync.sources.{$key}");

        return is_string($url) && $url !== '' ? $url : null;
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

    private function sanitize(SanitizerFactory $factory, string $downloaded): string
    {
        $this->info('Sanitizing...');

        $disk = Storage::disk((string) config('db-snapshot-sync.disk'));

        return $factory->forDefaultConnection()->sanitize($disk, $downloaded);
    }

    private function loadIntoLocalDb(string $name): void
    {
        $this->info('Loading into local DB...');

        $flags = (array) config('db-snapshot-sync.load', []);

        $arguments = ['name' => preg_replace('/\.sql(\.gz)?$/', '', $name)];

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
