<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Compression;

use InvalidArgumentException;
use Spatie\DbDumper\Compressors\Compressor;
use Spatie\DbDumper\Compressors\GzipCompressor;

/**
 * The formats a snapshot is stored in. A file's real format is read from its
 * magic bytes (sniff()); its extension (.sql, .sql.gz, .sql.zst) only decides
 * which files count as snapshots and what spatie calls them.
 */
enum Compression: string
{
    case None = 'none';
    case Gzip = 'gzip';
    case Zstd = 'zstd';

    private const string SNAPSHOT_PATTERN = '/\.sql(\.gz|\.zst)?$/';

    /**
     * The format `snapshot:create --compress` writes, from
     * db-snapshot-sync.compression.format.
     */
    public static function configured(): self
    {
        $format = config('db-snapshot-sync.compression.format', 'gzip');

        return match ($format) {
            'gzip' => self::Gzip,
            'zstd' => self::Zstd,
            default => throw new InvalidArgumentException('db-snapshot-sync.compression.format must be gzip or zstd, got ['.(is_scalar($format) ? $format : get_debug_type($format)).'].'),
        };
    }

    /**
     * The format of the file at $path by its magic bytes, whatever its name.
     */
    public static function sniff(string $path): self
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return self::None;
        }

        $magic = (string) fread($handle, 4);
        fclose($handle);

        return match (true) {
            str_starts_with($magic, "\x1f\x8b") => self::Gzip,
            $magic === "\x28\xb5\x2f\xfd" => self::Zstd,
            default => self::None,
        };
    }

    /**
     * Whether $file is named like a snapshot: .sql, .sql.gz or .sql.zst.
     */
    public static function isSnapshotName(string $file): bool
    {
        return preg_match(self::SNAPSHOT_PATTERN, $file) === 1;
    }

    /**
     * $file without its .sql / .sql.gz / .sql.zst suffix — the name spatie knows it by.
     */
    public static function stem(string $file): string
    {
        return (string) preg_replace(self::SNAPSHOT_PATTERN, '', $file);
    }

    /**
     * The extension this format adds after .sql, without the dot ('' for None).
     */
    public function extension(): string
    {
        return match ($this) {
            self::None => '',
            self::Gzip => 'gz',
            self::Zstd => 'zst',
        };
    }

    /**
     * The full snapshot suffix: .sql, .sql.gz or .sql.zst.
     */
    public function suffix(): string
    {
        return $this === self::None ? '.sql' : '.sql.'.$this->extension();
    }

    /**
     * The db-dumper compressor `snapshot:create --compress` pipes the dump through.
     */
    public function compressor(): ?Compressor
    {
        return match ($this) {
            self::None => null,
            self::Gzip => new GzipCompressor,
            self::Zstd => new ZstdCompressor(Zstd::fromConfig()),
        };
    }
}
