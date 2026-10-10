<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Phattarachai\DbSnapshotSyncLaravel\Events\SnapshotDrillCompleted;

/*
 * End-to-end against a real MySQL server. Skipped when none is reachable; CI
 * provides one (see run-tests.yml). Configure with
 * DB_SNAPSHOT_SYNC_MYSQL_HOST / _PORT / _USERNAME / _PASSWORD.
 */

beforeEach(function (): void {
    $host = (string) (getenv('DB_SNAPSHOT_SYNC_MYSQL_HOST') ?: '127.0.0.1');
    $port = (int) (getenv('DB_SNAPSHOT_SYNC_MYSQL_PORT') ?: 3306);
    $username = (string) (getenv('DB_SNAPSHOT_SYNC_MYSQL_USERNAME') ?: 'root');
    $password = (string) (getenv('DB_SNAPSHOT_SYNC_MYSQL_PASSWORD') ?: '');
    $database = 'db_snapshot_sync_test';

    try {
        $this->pdo = new PDO("mysql:host={$host};port={$port}", $username, $password);
        $this->pdo->exec("create database if not exists `{$database}`");
    } catch (PDOException) {
        $this->markTestSkipped('No MySQL server reachable.');
    }

    config([
        'database.connections.mysql' => [
            'driver' => 'mysql',
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ],
        'db-snapshot-sync.backup.disks' => ['spaces'],
    ]);

    $schema = DB::connection('mysql')->getSchemaBuilder();
    $schema->dropAllTables();
    $schema->create('users', fn ($table) => $table->string('name'));
    $schema->create('orders', fn ($table) => $table->integer('total'));
    DB::connection('mysql')->table('users')->insert([['name' => 'a'], ['name' => 'b']]);
    DB::connection('mysql')->table('orders')->insert([['total' => 1], ['total' => 2]]);

    $this->spaces = Storage::fake('spaces');
    Event::fake([SnapshotDrillCompleted::class]);
});

afterEach(function (): void {
    if (isset($this->pdo)) {
        DB::connection('mysql')->getSchemaBuilder()->dropAllTables();
        $this->pdo->exec('drop database if exists `db_snapshot_sync_test_restore_drill`');
    }
});

it('restores a MySQL copy into a scratch database and drops it, leaving the default connection alone', function (): void {
    $this->spaces->put('db/daily/newest.sql.gz', gzencode(implode("\n", [
        '-- MySQL dump 10.13  Distrib 8.4.0, for macos (arm64)',
        '--',
        'CREATE TABLE `users` (`name` varchar(255) NOT NULL);',
        "INSERT INTO `users` VALUES ('a'),('it\\'s b');",
        'CREATE TABLE `orders` (`total` int NOT NULL);',
        'INSERT INTO `orders` VALUES (1),(2);',
        '',
    ])));

    $this->artisan('snapshot:drill', ['--connection' => 'mysql'])
        ->expectsOutputToContain('Drill PASSED.')
        ->assertSuccessful();

    Event::assertDispatched(SnapshotDrillCompleted::class, fn (SnapshotDrillCompleted $e): bool => $e->result->passed()
        && $e->result->differences() === []
        && count($e->result->tables) === 2);

    expect($this->pdo->query("show databases like 'db_snapshot_sync_test_restore_drill'")->fetchColumn())->toBeFalse();
    expect(DB::getDefaultConnection())->toBe('pgsql');
    expect(DB::connection('mysql')->table('users')->count())->toBe(2);
});
