<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Compression;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * The zstd CLI and the flags snapshots are compressed with. PHP has no zstd
 * without a PECL extension, so every read and write goes through the binary.
 */
final readonly class Zstd
{
    /**
     * Searched after PATH: a launchd job, php-fpm or a non-interactive ssh shell
     * often runs with only /usr/bin:/bin, where Homebrew's zstd is not.
     */
    private const array EXTRA_DIRS = ['/opt/homebrew/bin', '/usr/local/bin', '/usr/bin'];

    public function __construct(
        public string $binary,
        public int $level = 10,
        public int $threads = 0,
    ) {}

    public static function fromConfig(): self
    {
        $configured = config('db-snapshot-sync.compression.zstd.binary');

        return new self(
            is_string($configured) && $configured !== '' ? $configured : self::find(),
            (int) config('db-snapshot-sync.compression.zstd.level', 10),
            (int) config('db-snapshot-sync.compression.zstd.threads', 0),
        );
    }

    /**
     * @return list<string>
     */
    public function compressCommand(): array
    {
        $command = [$this->binary, '-q', '-'.$this->level, '-T'.$this->threads];

        return $this->level > 19 ? [...$command, '--ultra'] : $command;
    }

    /**
     * @return list<string>
     */
    public function decompressCommand(): array
    {
        return [$this->binary, '-q', '-d', '-c'];
    }

    private static function find(): string
    {
        return new ExecutableFinder()->find('zstd', null, self::EXTRA_DIRS)
            ?? throw new RuntimeException('zstd was not found on PATH or in '.implode(', ', self::EXTRA_DIRS).'. Install it (brew install zstd / apt install zstd), or set db-snapshot-sync.compression.zstd.binary (DB_SNAPSHOT_SYNC_ZSTD).');
    }
}
