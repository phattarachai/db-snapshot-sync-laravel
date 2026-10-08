<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Support;

use Generator;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\DB;
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
 */
class PsqlLoader
{
    private const int CHUNK_BYTES = 1024 * 1024;

    private const string TRAILER = '-- PostgreSQL database dump complete';

    public function __construct(private readonly string $binary = 'psql') {}

    /**
     * Load the plain or gzipped dump at $path into $connection.
     *
     * @throws RuntimeException when psql cannot start or any statement fails
     */
    public function load(string $path, string $connection, bool $dropTables = true): void
    {
        $db = DB::connection($connection);
        $drop = $dropTables ? $this->dropAllTablesSql($db) : null;

        $process = new Process(
            command: [$this->binary, '--no-psqlrc', '--quiet', '--set', 'ON_ERROR_STOP=1', '--file', '-'],
            env: $this->environment($db),
            input: $this->input($path, $drop),
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
     * The statement Laravel's dropAllTables() would run, sent ahead of the dump
     * inside the same transaction instead of committed on its own beforehand.
     */
    private function dropAllTablesSql(Connection $db): ?string
    {
        $schema = $db->getSchemaBuilder();
        $excluded = $db->getConfig('dont_drop') ?? ['spatial_ref_sys'];
        $tables = [];

        foreach ($schema->getTables($schema->getCurrentSchemaListing()) as $table) {
            if (array_intersect([$table['name'], $table['schema_qualified_name']], $excluded) === []) {
                $tables[] = $table['schema_qualified_name'];
            }
        }

        if ($tables === []) {
            return null;
        }

        $grammar = $db->getSchemaGrammar();

        if (! $grammar instanceof PostgresGrammar) {
            throw new RuntimeException("Connection [{$db->getName()}] is not a pgsql connection; psql cannot load into it.");
        }

        return $grammar->compileDropAllTables($tables).";\n";
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
     * Stream the dump into psql in chunks, wrapped in BEGIN/COMMIT. gzread() reads
     * a plain file as-is and a multi-member gzip to its end, so one path serves
     * every snapshot shape. psql's own --single-transaction is not used: it commits
     * at end of input, however early that input ended.
     *
     * @return Generator<int, string>
     */
    private function input(string $path, ?string $drop): Generator
    {
        $handle = @gzopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open snapshot for loading: {$path}");
        }

        return (function () use ($handle, $drop): Generator {
            try {
                yield "BEGIN;\n".($drop ?? '');

                $tail = '';

                while (! gzeof($handle)) {
                    $chunk = gzread($handle, self::CHUNK_BYTES);

                    if ($chunk === false) {
                        throw new RuntimeException('Failed reading the snapshot while loading it.');
                    }

                    $tail = substr($tail.$chunk, -256);

                    yield $chunk;
                }

                if (! str_contains($tail, self::TRAILER)) {
                    throw new RuntimeException('The snapshot does not end with pg_dump\'s "'.self::TRAILER.'" trailer, so it is truncated or not a pg_dump. Nothing was committed.');
                }

                yield "\nCOMMIT;\n";
            } finally {
                gzclose($handle);
            }
        })();
    }
}
