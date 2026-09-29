<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Support;

/**
 * Identifies which dump tool wrote a snapshot by reading its header, so a dump
 * is never sanitized or loaded against a connection of the other engine — a
 * mysqldump loaded with --drop-tables onto a pgsql connection wipes it first
 * and only then fails.
 */
final class DumpEngine
{
    public const string MYSQL = 'mysql';

    public const string PGSQL = 'pgsql';

    /**
     * The header sits in the first few lines; newer mariadb-dump prepends a
     * sandbox-mode comment, and pg_dump 17+ a \restrict line, so scan a block
     * rather than only line one.
     */
    private const int HEADER_BYTES = 8192;

    /**
     * The engine that wrote the dump at $path (gzip or plain, sniffed by magic
     * bytes), or null when the header names neither.
     */
    public static function detect(string $path): ?string
    {
        $header = self::header($path);

        return match (true) {
            str_contains($header, '-- PostgreSQL database dump') => self::PGSQL,
            (bool) preg_match('/^-- (MySQL|MariaDB) dump /m', $header) => self::MYSQL,
            default => null,
        };
    }

    /**
     * The dump engine a Laravel connection driver loads — mariadb shares
     * mysqldump's format — or null for a driver no dump tool here targets.
     */
    public static function forDriver(string $driver): ?string
    {
        return match ($driver) {
            'pgsql' => self::PGSQL,
            'mysql', 'mariadb' => self::MYSQL,
            default => null,
        };
    }

    public static function label(string $engine): string
    {
        return $engine === self::PGSQL ? 'PostgreSQL (pg_dump)' : 'MySQL/MariaDB (mysqldump)';
    }

    private static function header(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return '';
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        $magic = (string) fread($handle, 2);
        fclose($handle);

        if ($magic === "\x1f\x8b") {
            $gz = gzopen($path, 'rb');
            if ($gz === false) {
                return '';
            }

            $header = (string) gzread($gz, self::HEADER_BYTES);
            gzclose($gz);

            return $header;
        }

        return (string) file_get_contents($path, false, null, 0, self::HEADER_BYTES);
    }
}
