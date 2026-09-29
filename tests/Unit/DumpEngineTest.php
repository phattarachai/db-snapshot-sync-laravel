<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Phattarachai\DbSnapshotSyncLaravel\Support\DumpEngine;

beforeEach(function (): void {
    $this->disk = Storage::fake('snapshots');
});

$pgDump = "--\n-- PostgreSQL database dump\n--\n\n\\restrict abc\nSET statement_timeout = 0;\n";
$mysqlDump = "-- MySQL dump 10.13  Distrib 8.0.36, for Linux (x86_64)\n--\n-- Host: localhost    Database: app\n/*!40101 SET NAMES utf8mb4 */;\n";
$mariaDump = "/*M!999999\\- enable the sandbox mode */ \n-- MariaDB dump 10.19-11.4.2-MariaDB, for Linux (x86_64)\n--\n";

it('detects the engine from a plain dump header', function (string $dump, string $engine): void {
    $this->disk->put('snap.sql', $dump);

    expect(DumpEngine::detect($this->disk->path('snap.sql')))->toBe($engine);
})->with([
    'pg_dump' => [$pgDump, DumpEngine::PGSQL],
    'mysqldump' => [$mysqlDump, DumpEngine::MYSQL],
    'mariadb-dump with a sandbox-mode first line' => [$mariaDump, DumpEngine::MYSQL],
]);

it('detects the engine through gzip, by magic bytes not extension', function () use ($mysqlDump): void {
    $this->disk->put('snap.sql', gzencode($mysqlDump, 9));

    expect(DumpEngine::detect($this->disk->path('snap.sql')))->toBe(DumpEngine::MYSQL);
});

it('returns null for an unrecognised header or a missing file', function (): void {
    $this->disk->put('snap.sql', "CREATE TABLE foo (id int);\n");

    expect(DumpEngine::detect($this->disk->path('snap.sql')))->toBeNull();
    expect(DumpEngine::detect($this->disk->path('missing.sql')))->toBeNull();
});

it('maps connection drivers to the engine they load', function (string $driver, ?string $engine): void {
    expect(DumpEngine::forDriver($driver))->toBe($engine);
})->with([
    ['pgsql', DumpEngine::PGSQL],
    ['mysql', DumpEngine::MYSQL],
    ['mariadb', DumpEngine::MYSQL],
    ['sqlite', null],
]);
