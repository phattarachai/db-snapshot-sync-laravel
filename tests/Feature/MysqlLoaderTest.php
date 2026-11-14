<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Phattarachai\DbSnapshotSyncLaravel\Support\MysqlLoader;

/*
 * End-to-end against a real MySQL server and mysql client. Skipped when no server
 * is reachable; CI provides one (see run-tests.yml). Configure with
 * DB_SNAPSHOT_SYNC_MYSQL_HOST / _PORT / _USERNAME / _PASSWORD.
 */

function mysqlDumpOf(string $tail = ''): string
{
    return implode("\n", [
        '-- MySQL dump 10.13  Distrib 8.4.0, for Linux (x86_64)',
        '/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;',
        'DROP TABLE IF EXISTS `users`;',
        'CREATE TABLE `users` (`name` varchar(255) NOT NULL);',
        "INSERT INTO `users` VALUES ('from-the-dump'),('O\\'Brien');",
        'DROP TABLE IF EXISTS `orders`;',
        'CREATE TABLE `orders` (`id` int NOT NULL);',
        'INSERT INTO `orders` VALUES (1),(2),(3);',
        $tail,
        '-- Dump completed on 2026-11-15  0:00:00',
        '',
    ]);
}

beforeEach(function (): void {
    $host = (string) (getenv('DB_SNAPSHOT_SYNC_MYSQL_HOST') ?: '127.0.0.1');
    $port = (int) (getenv('DB_SNAPSHOT_SYNC_MYSQL_PORT') ?: 3306);
    $username = (string) (getenv('DB_SNAPSHOT_SYNC_MYSQL_USERNAME') ?: 'root');
    $password = (string) (getenv('DB_SNAPSHOT_SYNC_MYSQL_PASSWORD') ?: '');
    $database = 'db_snapshot_sync_test';

    try {
        (new PDO("mysql:host={$host};port={$port}", $username, $password))
            ->exec("create database if not exists `{$database}`");
    } catch (PDOException) {
        $this->markTestSkipped('No MySQL server reachable.');
    }

    if (trim((string) shell_exec('command -v mysql')) === '') {
        $this->markTestSkipped('mysql is not on PATH.');
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
    ]);

    $schema = DB::connection('mysql')->getSchemaBuilder();
    $schema->dropAllTables();
    $schema->create('users', fn ($table) => $table->string('name'));
    $schema->create('local_only', fn ($table) => $table->string('note'));
    DB::connection('mysql')->table('users')->insert(['name' => 'before-the-load']);

    $this->dumpPath = tempnam(sys_get_temp_dir(), 'mysql-dump-');
});

afterEach(function (): void {
    if (isset($this->dumpPath) && is_file($this->dumpPath)) {
        unlink($this->dumpPath);
    }
});

it('streams a gzipped dump through the mysql client, then drops the tables it did not create', function (): void {
    file_put_contents($this->dumpPath, gzencode(mysqlDumpOf(), 9));

    (new MysqlLoader)->load($this->dumpPath, 'mysql');
    DB::purge('mysql');

    $db = DB::connection('mysql');
    expect($db->table('users')->pluck('name')->all())->toBe(['from-the-dump', "O'Brien"])
        ->and($db->table('orders')->count())->toBe(3)
        ->and($db->getSchemaBuilder()->hasTable('local_only'))->toBeFalse();
});

it('keeps the tables the dump does not create when drop-tables is off', function (): void {
    file_put_contents($this->dumpPath, mysqlDumpOf());

    (new MysqlLoader)->load($this->dumpPath, 'mysql', dropTables: false);
    DB::purge('mysql');

    expect(DB::connection('mysql')->getSchemaBuilder()->hasTable('local_only'))->toBeTrue();
});

it('fails loudly on a bad statement and never leaves the database empty', function (): void {
    file_put_contents($this->dumpPath, str_replace('INSERT INTO `orders`', 'INSERT INTO `missing`', mysqlDumpOf()));

    expect(fn () => (new MysqlLoader)->load($this->dumpPath, 'mysql'))
        ->toThrow(RuntimeException::class, "missing' doesn't exist");
    DB::purge('mysql');

    expect(DB::connection('mysql')->table('users')->value('name'))->toBe('from-the-dump')
        ->and(DB::connection('mysql')->getSchemaBuilder()->hasTable('local_only'))->toBeTrue();
});

it('names a missing mysql binary', function (): void {
    file_put_contents($this->dumpPath, mysqlDumpOf());

    expect(fn () => (new MysqlLoader('no-such-mysql'))->load($this->dumpPath, 'mysql'))
        ->toThrow(RuntimeException::class, '[no-such-mysql] was not found');
});
