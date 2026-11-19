<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\DbSnapshots\DbSnapshotsServiceProvider;

/*
 * spatie's `snapshot:load` end to end against a real PostgreSQL server, loading
 * the same dump twice into one database, as a CI runner that keeps its testing
 * database between jobs does. Skipped when no server is reachable; CI provides
 * one (see run-tests.yml). Configure with DB_SNAPSHOT_SYNC_PGSQL_HOST / _PORT /
 * _USERNAME / _PASSWORD.
 */

/**
 * A pg_dump-shaped dump holding the objects pg_dump writes without IF NOT EXISTS
 * besides tables: a collation, an enum, a domain, a function, a sequence and a view.
 */
function pgDumpWithNonTableObjects(): string
{
    return implode("\n", [
        '--',
        '-- PostgreSQL database dump',
        '--',
        'SET standard_conforming_strings = on;',
        "SELECT pg_catalog.set_config('search_path', '', false);",
        "CREATE COLLATION public.natural_sort (provider = icu, locale = 'und-u-kn');",
        "CREATE TYPE public.mood AS ENUM ('happy', 'sad');",
        'CREATE DOMAIN public.positive AS integer CHECK (VALUE > 0);',
        'CREATE FUNCTION public.answer() RETURNS integer LANGUAGE sql AS $$ SELECT 42 $$;',
        'CREATE SEQUENCE public.standalone_seq;',
        'CREATE TABLE public.people (id bigint NOT NULL, name text COLLATE public.natural_sort, mood public.mood, rank public.positive);',
        "CREATE VIEW public.happy_people AS SELECT id FROM public.people WHERE mood = 'happy';",
        "INSERT INTO public.people VALUES (1, 'a10', 'happy', 1), (2, 'a9', 'sad', 2);",
        '',
        '--',
        '-- PostgreSQL database dump complete',
        '--',
        '',
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
        'db-snapshots.disk' => 'snapshots',
        'db-snapshots.temporary_directory_path' => sys_get_temp_dir().'/db-snapshots-temp',
    ]);

    $disk = Storage::fake('snapshots');
    config(['filesystems.disks.snapshots' => ['driver' => 'local', 'root' => $disk->path('')]]);
    $disk->put('baseline.sql', pgDumpWithNonTableObjects());

    $this->app->register(DbSnapshotsServiceProvider::class);

    $db = DB::connection('pgsql');
    $db->statement('DROP SCHEMA IF EXISTS public CASCADE');
    $db->statement('CREATE SCHEMA public');
    $db->statement('CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public');
    $db->statement("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'snapshot_app') THEN CREATE ROLE snapshot_app; END IF; END $$");
    $db->statement('GRANT USAGE ON SCHEMA public TO snapshot_app');
    $db->statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO snapshot_app');
});

it('loads the same dump twice into one database, keeping extensions and default privileges', function (bool $stream): void {
    foreach ([1, 2] as $load) {
        $this->artisan('snapshot:load', ['name' => 'baseline', '--connection' => 'pgsql', '--force' => true, '--stream' => $stream])
            ->expectsOutputToContain('Snapshot `baseline` loaded')
            ->assertSuccessful();
        DB::purge('pgsql');
    }

    $db = DB::connection('pgsql');
    expect($db->table('happy_people')->pluck('id')->all())->toBe([1])
        ->and($db->table('people')->orderBy('name')->pluck('name')->all())->toBe(['a9', 'a10'])
        ->and($db->selectOne('select public.answer() as answer')->answer)->toBe(42)
        ->and($db->selectOne("select count(*) as n from pg_extension where extname = 'pg_trgm'")->n)->toBe(1)
        ->and($db->selectOne("select has_table_privilege('snapshot_app', 'public.people', 'SELECT') as granted")->granted)->toBeTrue()
        ->and($db->selectOne("select has_schema_privilege('snapshot_app', 'public', 'USAGE') as granted")->granted)->toBeTrue();
})->with(['streamed' => true, 'whole' => false]);
