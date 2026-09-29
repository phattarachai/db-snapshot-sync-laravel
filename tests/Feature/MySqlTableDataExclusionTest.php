<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\DbSnapshots\DbSnapshotsServiceProvider;

/*
 * End-to-end against a real MySQL server and mysqldump binary. Skipped when no
 * server is reachable; CI provides one (see run-tests.yml). Configure with
 * DB_SNAPSHOT_SYNC_MYSQL_HOST / _PORT / _USERNAME / _PASSWORD.
 */

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

    if (trim((string) shell_exec('command -v mysqldump')) === '') {
        $this->markTestSkipped('mysqldump is not on PATH.');
    }

    config([
        'database.default' => 'mysql',
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
        'db-snapshot-sync.dump.exclude_table_data' => ['jobs', 'cache'],
    ]);

    $schema = DB::connection('mysql')->getSchemaBuilder();
    $schema->dropAllTables();
    $schema->create('users', fn ($table) => $table->string('name'));
    $schema->create('jobs', fn ($table) => $table->string('payload'));
    DB::connection('mysql')->table('users')->insert(['name' => 'kept-user']);
    DB::connection('mysql')->table('jobs')->insert(['payload' => 'dropped-job']);

    $this->disk = Storage::fake('snapshots');
    config(['filesystems.disks.snapshots' => ['driver' => 'local', 'root' => $this->disk->path('')]]);
    $this->app->register(DbSnapshotsServiceProvider::class);
});

afterEach(function (): void {
    DB::connection('mysql')->getSchemaBuilder()->dropAllTables();
});

it('dumps an excluded table\'s schema but not its data, and skips a missing one', function (): void {
    $name = $this->withToken('secret-token')->postJson('/internal/snapshots')->assertOk()->json('name');

    $sql = (string) gzdecode_all($this->disk->path($name));

    expect($sql)
        ->toContain('CREATE TABLE `users`')
        ->toContain('kept-user')
        ->toContain('CREATE TABLE `jobs`')
        ->not->toContain('INSERT INTO `jobs`')
        ->not->toContain('dropped-job')
        ->not->toContain('`cache`');
});

it('loads back with the excluded table present and empty', function (): void {
    $name = $this->withToken('secret-token')->postJson('/internal/snapshots')->assertOk()->json('name');

    DB::connection('mysql')->getSchemaBuilder()->dropAllTables();

    Artisan::call('snapshot:load', ['name' => str_replace('.sql.gz', '', $name), '--force' => true, '--stream' => true]);

    expect(DB::connection('mysql')->table('users')->pluck('name')->all())->toBe(['kept-user'])
        ->and(DB::connection('mysql')->table('jobs')->count())->toBe(0);
});

/**
 * Read every gzip member, as gunzip and gzopen do (gzdecode stops at the first).
 */
function gzdecode_all(string $path): string
{
    $stream = gzopen($path, 'r');
    $sql = '';

    while (! gzeof($stream)) {
        $sql .= gzread($stream, 65536);
    }

    gzclose($stream);

    return $sql;
}
