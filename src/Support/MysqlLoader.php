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
 * Restores a mysqldump through the `mysql` client instead of spatie's
 * `snapshot:load`, whose default mode reads the whole dump into one string and
 * runs it as one statement (and drops every table before it starts).
 *
 * The dump streams in chunks, so memory stays flat whatever its size, and no
 * statement passes through Laravel's query events (no SQL breadcrumbs). mysqldump
 * replaces each table with its own `DROP TABLE IF EXISTS` + `CREATE TABLE`, so
 * nothing is dropped up front. Tables the dump doesn't create are dropped only
 * after it loaded, in the same client session. MySQL commits every DDL
 * statement, so unlike PostgreSQL a failure part-way leaves the tables before
 * it replaced and the rest as they were — never an empty database.
 */
class MysqlLoader
{
    private const int CHUNK_BYTES = 1024 * 1024;

    public function __construct(private readonly string $binary = 'mysql') {}

    /**
     * Load the plain, gzip or zstd dump at $path into $connection.
     *
     * @throws RuntimeException when the client cannot start or any statement fails
     */
    public function load(string $path, string $connection, bool $dropTables = true): void
    {
        $db = DB::connection($connection);

        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new RuntimeException("Connection [{$connection}] is not a mysql/mariadb connection; the mysql client cannot load into it.");
        }

        $before = $dropTables ? array_map(strval(...), array_column($db->select(
            "select table_name as name from information_schema.tables where table_schema = database() and table_type = 'BASE TABLE'"
        ), 'name')) : [];
        $defaults = $this->defaultsFile($db);

        $process = new Process(
            command: [$this->binary, "--defaults-extra-file={$defaults}", '--binary-mode', '--default-character-set=utf8mb4', (string) $db->getConfig('database')],
            input: $this->input($path, $before),
            timeout: null,
        );

        try {
            $process->run();
        } catch (ProcessException $e) {
            throw new RuntimeException("Could not run [{$this->binary}] to load the snapshot: {$e->getMessage()}", previous: $e);
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }

            @unlink($defaults);
        }

        if ($process->isSuccessful()) {
            return;
        }

        if ($process->getExitCode() === 127) {
            throw new RuntimeException("[{$this->binary}] was not found. Install the MySQL client, or point DB_SNAPSHOT_SYNC_MYSQL at its mysql binary. Nothing was loaded.");
        }

        $error = trim($process->getErrorOutput());

        throw new RuntimeException(
            "mysql failed to load {$path} into [{$connection}] (exit {$process->getExitCode()}). MySQL commits each table as it goes: the tables before the failing statement were replaced, the rest are as they were."
            .($error !== '' ? PHP_EOL.mb_substr($error, -2000) : '')
        );
    }

    /**
     * Credentials in a 0600 option file, so the password never reaches argv or the environment.
     */
    private function defaultsFile(Connection $db): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'mysql-restore-');
        chmod($path, 0o600);

        $options = array_filter([
            'user' => $db->getConfig('username'),
            'password' => $db->getConfig('password'),
            'host' => $db->getConfig('host'),
            'port' => $db->getConfig('port'),
            'socket' => $db->getConfig('unix_socket'),
        ], fn (mixed $value): bool => is_scalar($value) && $value !== '');

        $lines = array_map(
            fn (string $key, mixed $value): string => $key.'="'.addcslashes((string) $value, '"\\').'"',
            array_keys($options),
            $options,
        );

        file_put_contents($path, "[client]\n".implode("\n", $lines)."\n");

        return $path;
    }

    /**
     * Stream the dump into the client, noting each table it creates; then drop
     * the tables that were there before and the dump did not create.
     *
     * @param  list<string>  $before
     * @return Generator<int, string>
     */
    private function input(string $path, array $before): Generator
    {
        $stream = SnapshotStream::read($path);

        return (function () use ($stream, $before): Generator {
            try {
                $created = [];
                $carry = '';

                while (! feof($stream->handle)) {
                    $chunk = fread($stream->handle, self::CHUNK_BYTES);

                    if ($chunk === false) {
                        throw new RuntimeException('Failed reading the snapshot while loading it.');
                    }

                    $scan = $carry.$chunk;
                    preg_match_all('/^CREATE TABLE (?:IF NOT EXISTS )?`([^`]+)`/m', $scan, $matches);
                    $created = [...$created, ...$matches[1]];
                    $carry = substr($scan, -256);

                    yield $chunk;
                }

                $stream->close();

                $leftover = array_values(array_diff($before, $created));

                if ($leftover !== []) {
                    yield "\nSET FOREIGN_KEY_CHECKS = 0;\nDROP TABLE IF EXISTS "
                        .implode(', ', array_map(fn (string $table): string => '`'.str_replace('`', '``', $table).'`', $leftover))
                        .";\nSET FOREIGN_KEY_CHECKS = 1;\n";
                }
            } finally {
                $stream->abort();
            }
        })();
    }
}
