<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Phattarachai\DbSnapshotSyncLaravel\Support\PsqlLoader;

/*
 * End-to-end against a real PostgreSQL server and psql binary. Skipped when no
 * server is reachable; CI provides one (see run-tests.yml). Configure with
 * DB_SNAPSHOT_SYNC_PGSQL_HOST / _PORT / _USERNAME / _PASSWORD.
 */

/**
 * A pg_dump-shaped dump: a value with a backslash before a doubled quote, then
 * a second table after it. spatie's stream loader loses everything past the
 * backslash without an error.
 */
function pgDumpWithBackslashQuote(bool $complete = true): string
{
    return implode("\n", [
        '--',
        '-- PostgreSQL database dump',
        '--',
        'SET standard_conforming_strings = on;',
        "SELECT pg_catalog.set_config('search_path', '', false);",
        'CREATE TABLE public.lyrics (id bigint NOT NULL, body text);',
        'CREATE TABLE public.tracks (id bigint NOT NULL, title text);',
        'INSERT INTO public.lyrics VALUES',
        "\t(1, 'Will you burn by the things I\\''ve said?",
        "Now I\\''m in love but I don\\''t know how'),",
        "\t(2, 'C:\\');",
        'INSERT INTO public.tracks VALUES',
        "\t(1, 'after the backslash'),",
        "\t(2, 'still here');",
        '',
        ...($complete ? ['--', '-- PostgreSQL database dump complete', '--', ''] : []),
    ]);
}

beforeEach(function (): void {
    $host = (string) (getenv('DB_SNAPSHOT_SYNC_PGSQL_HOST') ?: '127.0.0.1');
    $port = (int) (getenv('DB_SNAPSHOT_SYNC_PGSQL_PORT') ?: 5432);
    $username = (string) (getenv('DB_SNAPSHOT_SYNC_PGSQL_USERNAME') ?: 'postgres');
    $password = (string) (getenv('DB_SNAPSHOT_SYNC_PGSQL_PASSWORD') ?: '');
    $database = 'db_snapshot_sync_test';

    try {
        $pdo = new PDO("pgsql:host={$host};port={$port};dbname=postgres", $username, $password);

        if ($pdo->query("select 1 from pg_database where datname = '{$database}'")->fetchColumn() === false) {
            $pdo->exec("create database {$database}");
        }
    } catch (PDOException) {
        $this->markTestSkipped('No PostgreSQL server reachable.');
    }

    if (trim((string) shell_exec('command -v psql')) === '') {
        $this->markTestSkipped('psql is not on PATH.');
    }

    config([
        'database.connections.pgsql' => [
            'driver' => 'pgsql',
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
        ],
    ]);

    $schema = DB::connection('pgsql')->getSchemaBuilder();
    $schema->dropAllTables();
    $schema->create('existing', fn ($table) => $table->string('name'));
    DB::connection('pgsql')->table('existing')->insert(['name' => 'before-the-load']);

    $this->dumpPath = tempnam(sys_get_temp_dir(), 'pg-dump-');
});

afterEach(function (): void {
    if (isset($this->dumpPath) && is_file($this->dumpPath)) {
        unlink($this->dumpPath);
    }
});

it('loads every statement after a backslash before a quote, and drops the old tables', function (): void {
    file_put_contents($this->dumpPath, gzencode(pgDumpWithBackslashQuote(), 9));

    (new PsqlLoader)->load($this->dumpPath, 'pgsql');
    DB::purge('pgsql');

    $db = DB::connection('pgsql');
    expect($db->table('lyrics')->orderBy('id')->pluck('body')->all())->toBe([
        "Will you burn by the things I\\'ve said?\nNow I\\'m in love but I don\\'t know how",
        'C:\\',
    ]);
    expect($db->table('tracks')->count())->toBe(2);
    expect($db->getSchemaBuilder()->hasTable('existing'))->toBeFalse();
});

it('loads a plain .sql dump too', function (): void {
    file_put_contents($this->dumpPath, pgDumpWithBackslashQuote());

    (new PsqlLoader)->load($this->dumpPath, 'pgsql');
    DB::purge('pgsql');

    expect(DB::connection('pgsql')->table('tracks')->count())->toBe(2);
});

it('fails loudly on a bad statement and rolls back to the database as it was', function (): void {
    file_put_contents($this->dumpPath, str_replace('INSERT INTO public.tracks', 'INSERT INTO public.missing', pgDumpWithBackslashQuote()));

    expect(fn () => (new PsqlLoader)->load($this->dumpPath, 'pgsql'))
        ->toThrow(RuntimeException::class, 'relation "public.missing" does not exist');
    DB::purge('pgsql');

    $schema = DB::connection('pgsql')->getSchemaBuilder();
    expect($schema->hasTable('lyrics'))->toBeFalse();
    expect(DB::connection('pgsql')->table('existing')->value('name'))->toBe('before-the-load');
});

it('refuses a truncated dump without committing any of it', function (): void {
    file_put_contents($this->dumpPath, gzencode(pgDumpWithBackslashQuote(complete: false), 9));

    expect(fn () => (new PsqlLoader)->load($this->dumpPath, 'pgsql'))
        ->toThrow(RuntimeException::class, 'truncated');
    DB::purge('pgsql');

    expect(DB::connection('pgsql')->getSchemaBuilder()->hasTable('tracks'))->toBeFalse();
    expect(DB::connection('pgsql')->table('existing')->value('name'))->toBe('before-the-load');
});

it('keeps existing tables when drop-tables is off', function (): void {
    file_put_contents($this->dumpPath, pgDumpWithBackslashQuote());

    (new PsqlLoader)->load($this->dumpPath, 'pgsql', dropTables: false);
    DB::purge('pgsql');

    expect(DB::connection('pgsql')->table('existing')->value('name'))->toBe('before-the-load');
    expect(DB::connection('pgsql')->table('tracks')->count())->toBe(2);
});

it('names a missing psql binary', function (): void {
    file_put_contents($this->dumpPath, pgDumpWithBackslashQuote());

    expect(fn () => (new PsqlLoader('no-such-psql'))->load($this->dumpPath, 'pgsql'))
        ->toThrow(RuntimeException::class, '[no-such-psql] was not found');
});
