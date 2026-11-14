<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Transfer;

use InvalidArgumentException;

/**
 * A source box reached over ssh: the ~/.ssh/config Host alias, the app's
 * directory on the box (`…/current` in a release layout) and, when the ssh
 * user is not the app's user, the user artisan runs as (through `sudo -n`).
 * A `wrapper` (a root-owned script that cds into the app and runs artisan
 * snapshot:export) replaces the path and php, for a sudoers rule that allows
 * that one script and nothing else.
 */
final readonly class SshSource
{
    /**
     * @param  array<string, scalar>  $sshOptions
     */
    public function __construct(
        public string $host,
        public string $path,
        public ?string $runAs = null,
        public string $php = 'php',
        public array $sshOptions = [],
        public ?string $wrapper = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $source  one entry of db-snapshot-sync.sources
     */
    public static function fromConfig(string $key, array $source): self
    {
        $host = $source['ssh'] ?? null;
        $path = $source['path'] ?? null;
        $wrapper = $source['wrapper'] ?? null;
        $wrapper = is_string($wrapper) && $wrapper !== '' ? $wrapper : null;

        if (! is_string($host) || $host === '') {
            throw new InvalidArgumentException("Source [{$key}] has no ssh host.");
        }

        if ($wrapper === null && (! is_string($path) || $path === '')) {
            throw new InvalidArgumentException("Source [{$key}] sets ssh [{$host}] but no path: set the app's directory on the box (…/current in a release layout).");
        }

        $runAs = $source['run_as'] ?? null;
        $php = $source['php'] ?? null;

        return new self(
            host: $host,
            path: is_string($path) ? $path : '',
            runAs: is_string($runAs) && $runAs !== '' ? $runAs : null,
            php: is_string($php) && $php !== '' ? $php : (string) config('db-snapshot-sync.ssh.php', 'php'),
            sshOptions: [
                ...(array) config('db-snapshot-sync.ssh.options', []),
                ...(array) ($source['ssh_options'] ?? []),
            ],
            wrapper: $wrapper,
        );
    }

    /**
     * The command line the box's shell runs: cd into the app, then artisan as the
     * app's user. PHP notices go to stderr so they can never corrupt the dump on
     * stdout. Every piece is shell-quoted.
     *
     * @param  list<string>  $artisan  the artisan command and its arguments
     */
    public function remoteCommand(array $artisan): string
    {
        if ($this->wrapper !== null) {
            $asUser = $this->runAs === null ? [] : ['sudo', '-n', '-u', $this->runAs];

            return implode(' ', array_map(escapeshellarg(...), [...$asUser, $this->wrapper, ...array_slice($artisan, 1), '--no-ansi', '--no-interaction']));
        }

        $php = [$this->php, '-d', 'display_errors=stderr', 'artisan', ...$artisan, '--no-ansi', '--no-interaction'];

        $asUser = $this->runAs === null ? [] : ['sudo', '-n', '-u', $this->runAs, 'env', 'HOME=/tmp'];

        return 'cd '.escapeshellarg($this->path).' && '.implode(' ', array_map(escapeshellarg(...), [...$asUser, ...$php]));
    }

    /**
     * @param  list<string>  $artisan
     * @return list<string>
     */
    public function command(string $binary, array $artisan): array
    {
        $options = [];

        foreach ($this->sshOptions as $name => $value) {
            $options[] = '-o';
            $options[] = $name.'='.(is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value);
        }

        return [$binary, ...$options, $this->host, $this->remoteCommand($artisan)];
    }

    public function label(): string
    {
        return "ssh {$this->host}:".($this->wrapper ?? $this->path).($this->runAs === null ? '' : " (as {$this->runAs})");
    }
}
