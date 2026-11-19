<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Support;

use Generator;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Phattarachai\DbSnapshotSyncLaravel\Compression\SnapshotStream;
use RuntimeException;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

/**
 * Restores a pg_dump through psql instead of spatie's `snapshot:load --stream`.
 *
 * spatie splits the dump into statements in PHP and treats a backslash inside a
 * '…' literal as an escape. pg_dump writes with standard_conforming_strings = on,
 * where a backslash is literal, so a value such as `I\'ve` — dumped as 'I\''ve' —
 * flips its quote tracking. From there no newline ends a statement; the rest of
 * the file folds into one trailing statement that never ends in `;`, which spatie
 * discards without an error, and the load reports success with every later table
 * empty.
 *
 * psql is pg_dump's own parser, and ON_ERROR_STOP turns any failed statement into
 * a non-zero exit. The drop and the restore run in one transaction whose COMMIT is
 * sent only once the whole dump has been read and ends in pg_dump's completion
 * trailer — so a failed statement, a read error or a truncated download all roll
 * back to the database as it was instead of leaving it half-filled.
 *
 * "Drop" means every object of the connection's schemas that an extension does
 * not own (tables, views, sequences, types, domains, functions, collations — see
 * PostgresObjects), so a dump restores over
 * a database that already holds the same objects. Extensions survive, so the
 * dump's `COMMENT ON EXTENSION` (owner-only) is skipped, with the sanitizer's
 * pgsql `drop_prefixes`; `CREATE EXTENSION IF NOT EXISTS` stays and is a no-op
 * when the extension is there.
 */
class PsqlLoader
{
    private const int CHUNK_BYTES = 1024 * 1024;

    private const string TRAILER = '-- PostgreSQL database dump complete';

    private const array ALWAYS_SKIPPED = ['COMMENT ON EXTENSION '];

    public function __construct(private readonly string $binary = 'psql') {}

    /**
     * Load the plain, gzip or zstd dump at $path into $connection.
     *
     * @throws RuntimeException when psql cannot start or any statement fails
     */
    public function load(string $path, string $connection, bool $dropTables = true, ?string $role = null): void
    {
        $this->refuseCustomFormat($path);

        $db = DB::connection($connection);
        $drop = $dropTables ? $this->dropObjectsSql($db) : null;
        $setRole = $role === null || $role === '' ? '' : 'SET ROLE "'.str_replace('"', '""', $role)."\";\n";

        $process = new Process(
            command: [$this->binary, '--no-psqlrc', '--quiet', '--set', 'ON_ERROR_STOP=1', '--file', '-'],
            env: $this->environment($db),
            input: $this->input($path, ($drop ?? '').$setRole),
            timeout: null,
        );

        try {
            $process->run();
        } catch (ProcessException $e) {
            throw new RuntimeException("Could not run [{$this->binary}] to load the snapshot: {$e->getMessage()}", previous: $e);
        } finally {
            // An exception from the input stream leaves psql mid-transaction;
            // killing it closes the session, which rolls the transaction back.
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }

        if ($process->isSuccessful()) {
            return;
        }

        $error = trim($process->getErrorOutput());

        if ($process->getExitCode() === 127) {
            throw new RuntimeException("[{$this->binary}] was not found. Install the PostgreSQL client, or point DB_SNAPSHOT_SYNC_PSQL at its psql binary. Nothing was loaded.");
        }

        throw new RuntimeException(
            "psql failed to load {$path} into [{$connection}] (exit {$process->getExitCode()}); the load was rolled back."
            .($error !== '' ? PHP_EOL.mb_substr($error, -2000) : '')
        );
    }

    /**
     * DROP … IF EXISTS … CASCADE for every object of the connection's schemas
     * (see PostgresObjects), sent ahead of the dump inside the same transaction
     * instead of committed on its own beforehand.
     */
    private function dropObjectsSql(Connection $db): ?string
    {
        if ($db->getDriverName() !== 'pgsql') {
            throw new RuntimeException("Connection [{$db->getName()}] is not a pgsql connection; psql cannot load into it.");
        }

        $statements = PostgresObjects::dropStatements($db);

        return $statements === [] ? null : implode("\n", $statements)."\n";
    }

    /**
     * pg_dump's custom format (-Fc) is pg_restore's, not psql's.
     */
    private function refuseCustomFormat(string $path): void
    {
        $stream = SnapshotStream::read($path);
        $magic = (string) fread($stream->handle, 5);
        $stream->abort();

        if ($magic === 'PGDMP') {
            throw new RuntimeException("{$path} is a pg_dump custom-format archive (-Fc); restore it with pg_restore --single-transaction --no-owner. Nothing was loaded.");
        }
    }

    /**
     * Connection settings as libpq variables, so the password never reaches argv.
     *
     * @return array<string, string>
     */
    private function environment(Connection $db): array
    {
        $host = $db->getConfig('host');

        $env = [
            'PGHOST' => is_array($host) ? reset($host) : $host,
            'PGPORT' => $db->getConfig('port'),
            'PGUSER' => $db->getConfig('username'),
            'PGPASSWORD' => $db->getConfig('password'),
            'PGDATABASE' => $db->getConfig('database'),
            'PGSSLMODE' => $db->getConfig('sslmode'),
            // The leading DROP ... CASCADE would otherwise NOTICE once per dependent object.
            'PGOPTIONS' => '-c client_min_messages=warning',
        ];

        return array_map(strval(...), array_filter($env, fn (mixed $value): bool => is_scalar($value) && $value !== ''));
    }

    /**
     * Stream the dump into psql in chunks, wrapped in BEGIN/COMMIT. SnapshotStream
     * decompresses every snapshot shape, and its close() fails a zstd file that is
     * corrupt, before COMMIT is sent. psql's own --single-transaction is not used:
     * it commits at end of input, however early that input ended.
     *
     * @return Generator<int, string>
     */
    private function input(string $path, string $preamble): Generator
    {
        $stream = SnapshotStream::read($path);
        $skipped = $this->skippedPrefixes();

        return (function () use ($stream, $preamble, $skipped): Generator {
            try {
                yield "BEGIN;\n".$preamble;

                $tail = '';
                $carry = '';

                while (! feof($stream->handle)) {
                    $chunk = fread($stream->handle, self::CHUNK_BYTES);

                    if ($chunk === false) {
                        throw new RuntimeException('Failed reading the snapshot while loading it.');
                    }

                    $tail = substr($tail.$chunk, -256);
                    $data = $carry.$chunk;
                    $cut = strrpos($data, "\n");

                    if ($cut === false) {
                        $carry = $data;

                        continue;
                    }

                    $carry = substr($data, $cut + 1);

                    yield $this->withoutSkippedLines(substr($data, 0, $cut + 1), $skipped);
                }

                if ($carry !== '') {
                    yield $this->withoutSkippedLines($carry, $skipped);
                }

                $stream->close();

                if (! str_contains($tail, self::TRAILER)) {
                    throw new RuntimeException('The snapshot does not end with pg_dump\'s "'.self::TRAILER.'" trailer, so it is truncated or not a pg_dump. Nothing was committed.');
                }

                yield "\nCOMMIT;\n";
            } finally {
                $stream->abort();
            }
        })();
    }

    /**
     * @return list<string>
     */
    private function skippedPrefixes(): array
    {
        return array_values(array_unique(array_filter(array_map('strval', [
            ...self::ALWAYS_SKIPPED,
            ...(array) config('db-snapshot-sync.sanitizer.pgsql.drop_prefixes', []),
        ]))));
    }

    /**
     * @param  list<string>  $skipped
     */
    private function withoutSkippedLines(string $lines, array $skipped): string
    {
        if (! array_any($skipped, fn (string $prefix): bool => str_contains($lines, $prefix))) {
            return $lines;
        }

        return implode("\n", array_filter(
            explode("\n", $lines),
            fn (string $line): bool => ! array_any($skipped, fn (string $prefix): bool => str_starts_with($line, $prefix)),
        ));
    }
}
