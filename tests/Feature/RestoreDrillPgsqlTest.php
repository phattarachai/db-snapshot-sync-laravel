<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Phattarachai\DbSnapshotSyncLaravel\Events\SnapshotDrillCompleted;

/*
 * End-to-end against a real PostgreSQL server and psql binary. Skipped when no
 * server is reachable; CI provides one (see run-tests.yml). Configure with
 * DB_SNAPSHOT_SYNC_PGSQL_HOST / _PORT / _USERNAME / _PASSWORD.
 */

/**
 * @param  list<string>  $statements
 */
function pgDrillDump(array $statements, bool $complete = true): string
{
    return implode("\n", [
        '--',
        '-- PostgreSQL database dump',
        '--',
        'SET standard_conforming_strings = on;',
        "SELECT pg_catalog.set_config('search_path', '', false);",
        ...$statements,
        '',
        ...($complete ? ['--', '-- PostgreSQL database dump complete', '--', ''] : []),
    ]);
}

function pgScratchExists(PDO $pdo): bool
{
    return $pdo->query("select 1 from pg_database where datname = 'db_snapshot_sync_test_restore_drill'")->fetchColumn() !== false;
}

/**
 * @return array<string, int>
 */
function pgLiveCounts(): array
{
    $db = DB::connection('pgsql');

    return collect(['users', 'orders', 'sessions'])->mapWithKeys(fn (string $t): array => [$t => $db->table($t)->count()])->all();
}

beforeEach(function (): void {
    $host = (string) (getenv('DB_SNAPSHOT_SYNC_PGSQL_HOST') ?: '127.0.0.1');
    $port = (int) (getenv('DB_SNAPSHOT_SYNC_PGSQL_PORT') ?: 5432);
    $username = (string) (getenv('DB_SNAPSHOT_SYNC_PGSQL_USERNAME') ?: 'postgres');
    $password = (string) (getenv('DB_SNAPSHOT_SYNC_PGSQL_PASSWORD') ?: '');
    $database = 'db_snapshot_sync_test';

    try {
        $this->pdo = new PDO("pgsql:host={$host};port={$port};dbname=postgres", $username, $password);

        if ($this->pdo->query("select 1 from pg_database where datname = '{$database}'")->fetchColumn() === false) {
            $this->pdo->exec("create database {$database}");
        }
    } catch (PDOException) {
        $this->markTestSkipped('No PostgreSQL server reachable.');
    }

    if (trim((string) shell_exec('command -v psql')) === '') {
        $this->markTestSkipped('psql is not on PATH.');
    }

    config([
        'database.default' => 'pgsql',
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
        'db-snapshot-sync.backup.disks' => ['spaces'],
    ]);

    $schema = DB::connection('pgsql')->getSchemaBuilder();
    $schema->dropAllTables();
    $schema->create('users', fn ($table) => $table->string('name'));
    $schema->create('orders', fn ($table) => $table->integer('total'));
    $schema->create('sessions', fn ($table) => $table->string('id'));
    DB::connection('pgsql')->table('users')->insert([['name' => 'a'], ['name' => 'b'], ['name' => 'signed up since']]);
    DB::connection('pgsql')->table('orders')->insert([['total' => 1], ['total' => 2]]);
    DB::connection('pgsql')->table('sessions')->insert([['id' => 's1'], ['id' => 's2']]);

    $this->spaces = Storage::fake('spaces');
    Event::fake([SnapshotDrillCompleted::class]);
});

afterEach(function (): void {
    if (isset($this->pdo)) {
        $this->pdo->exec('drop database if exists db_snapshot_sync_test_restore_drill');
    }
});

it('restores the newest off-site copy into a scratch database, diffs row counts, and drops it', function (): void {
    $this->spaces->put('db/daily/older.sql.gz', gzencode(pgDrillDump(['broken;'])));
    touch($this->spaces->path('db/daily/older.sql.gz'), time() - 86400);
    $this->spaces->put('db/daily/newest.sql.gz', gzencode(pgDrillDump([
        'CREATE TABLE public.users (name character varying(255) NOT NULL);',
        'CREATE TABLE public.orders (total integer NOT NULL);',
        'CREATE TABLE public.sessions (id character varying(255) NOT NULL);',
        "INSERT INTO public.users VALUES ('a'), ('it\\''s b');",
        'INSERT INTO public.orders VALUES (1), (2);',
    ])));
    $before = pgLiveCounts();

    $this->artisan('snapshot:drill')
        ->expectsOutputToContain('Snapshot: [spaces] db/daily/newest.sql.gz')
        ->expectsOutputToContain('Restored into db_snapshot_sync_test_restore_drill')
        ->expectsOutputToContain('Dropped scratch database db_snapshot_sync_test_restore_drill.')
        ->expectsOutputToContain('Drill PASSED.')
        ->assertSuccessful();

    Event::assertDispatched(SnapshotDrillCompleted::class, function (SnapshotDrillCompleted $event): bool {
        $result = $event->result;

        return $result->passed()
            && $result->disk === 'spaces'
            && $result->snapshot === 'db/daily/newest.sql.gz'
            && $result->differences() === [
                ['table' => 'public.sessions', 'live' => 2, 'restored' => 0],
                ['table' => 'public.users', 'live' => 3, 'restored' => 2],
            ];
    });

    expect(pgScratchExists($this->pdo))->toBeFalse();
    expect(pgLiveCounts())->toBe($before);
    expect(glob(storage_path('app/db-snapshot-sync-drill/*')))->toBe([]);
});

it('fails on a table missing from the restore or one restored empty', function (): void {
    $this->spaces->put('db/daily/newest.sql', pgDrillDump([
        'CREATE TABLE public.users (name character varying(255) NOT NULL);',
        'CREATE TABLE public.orders (total integer NOT NULL);',
        "INSERT INTO public.users VALUES ('a'), ('b'), ('c');",
    ]));

    $this->artisan('snapshot:drill', ['--disk' => 'spaces', '--connection' => 'pgsql'])
        ->expectsOutputToContain('Missing from the restore: public.sessions')
        ->expectsOutputToContain('Restored empty, has rows live: public.orders')
        ->expectsOutputToContain('Drill FAILED.')
        ->assertFailed();

    Event::assertDispatched(SnapshotDrillCompleted::class, fn (SnapshotDrillCompleted $e): bool => ! $e->result->passed()
        && $e->result->missing === ['public.sessions']
        && $e->result->emptied === ['public.orders']);
    expect(pgScratchExists($this->pdo))->toBeFalse();
});

it('fails on a truncated copy and still drops the scratch database', function (): void {
    $this->spaces->put('db/daily/newest.sql.gz', gzencode(pgDrillDump(['CREATE TABLE public.users (name text);'], complete: false)));

    $this->artisan('snapshot:drill')
        ->expectsOutputToContain('truncated or not a pg_dump')
        ->assertFailed();

    expect(pgScratchExists($this->pdo))->toBeFalse();
    expect(DB::connection('pgsql')->table('users')->count())->toBe(3);
});

it('replaces a scratch database left behind by a killed run', function (): void {
    $this->pdo->exec('create database db_snapshot_sync_test_restore_drill');
    $this->spaces->put('db/daily/newest.sql', pgDrillDump([
        'CREATE TABLE public.users (name text);',
        'CREATE TABLE public.orders (total integer);',
        'CREATE TABLE public.sessions (id text);',
        "INSERT INTO public.users VALUES ('a');",
        'INSERT INTO public.orders VALUES (1);',
    ]));

    $this->artisan('snapshot:drill')->assertSuccessful();

    expect(pgScratchExists($this->pdo))->toBeFalse();
});

it('refuses a dump from the other engine before creating anything', function (): void {
    $this->spaces->put('db/daily/newest.sql', "-- MySQL dump 10.13  Distrib 8.4.0\nCREATE TABLE `users` (`name` text);\n");

    $this->artisan('snapshot:drill')
        ->expectsOutputToContain('is a MySQL/MariaDB (mysqldump) dump, but connection [pgsql] uses the pgsql driver')
        ->assertFailed();

    expect(pgScratchExists($this->pdo))->toBeFalse();
});

it('fails when the target holds no daily copy', function (): void {
    $this->artisan('snapshot:drill')
        ->expectsOutputToContain('[spaces] holds no daily copy under db/daily/ to drill.')
        ->assertFailed();

    Event::assertDispatched(SnapshotDrillCompleted::class, fn (SnapshotDrillCompleted $e): bool => $e->result->snapshot === null && ! $e->result->passed());
});
