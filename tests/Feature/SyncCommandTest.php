<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Phattarachai\DbSnapshotSyncLaravel\DbSnapshotSync;
use Phattarachai\DbSnapshotSyncLaravel\Support\MysqlLoader;
use Phattarachai\DbSnapshotSyncLaravel\Support\PsqlLoader;

$dump = "\\restrict abc\nSET transaction_timeout = 0;\nCREATE TABLE foo (id int);\n";
$pgDump = "--\n-- PostgreSQL database dump\n--\n\\restrict abc\nCREATE TABLE foo (id int);\n";
$mysqlDump = "-- MySQL dump 10.13  Distrib 8.0.36, for Linux (x86_64)\n/*!50013 DEFINER=`root`@`%` SQL SECURITY DEFINER */\nCREATE TABLE bar (id int);\n";

/**
 * Stand in for the mysql client loader a mysql/mariadb target uses.
 *
 * @return ArrayObject<string, mixed> the arguments the sync passed, once it runs
 */
function fakeMysqlLoad(): ArrayObject
{
    $calls = new ArrayObject;

    app()->instance(MysqlLoader::class, new class($calls) extends MysqlLoader
    {
        /** @param ArrayObject<string, mixed> $calls */
        public function __construct(private readonly ArrayObject $calls)
        {
            parent::__construct();
        }

        public function load(string $path, string $connection, bool $dropTables = true): void
        {
            $this->calls['path'] = $path;
            $this->calls['connection'] = $connection;
            $this->calls['drop-tables'] = $dropTables;
        }
    });

    return $calls;
}

/**
 * Stand in for the psql loader a pgsql target uses instead of snapshot:load.
 *
 * @return ArrayObject<string, mixed> the arguments the sync passed, once it runs
 */
function fakePsqlLoad(?Throwable $failWith = null): ArrayObject
{
    $calls = new ArrayObject;

    app()->instance(PsqlLoader::class, new class($calls, $failWith) extends PsqlLoader
    {
        /** @param ArrayObject<string, mixed> $calls */
        public function __construct(private readonly ArrayObject $calls, private readonly ?Throwable $failWith)
        {
            parent::__construct();
        }

        public function load(string $path, string $connection, bool $dropTables = true, ?string $role = null): void
        {
            $this->calls['path'] = $path;
            $this->calls['connection'] = $connection;
            $this->calls['drop-tables'] = $dropTables;

            if ($this->failWith instanceof Throwable) {
                throw $this->failWith;
            }
        }
    });

    return $calls;
}

it('names a plain .sql download by its content, not a .gz extension', function () use ($dump): void {
    config([
        'db-snapshot-sync.sync.allowed_environments' => ['testing'],
        'db-snapshot-sync.sanitizer.pgsql.drop_prefixes' => ['\\restrict', 'SET transaction_timeout'],
    ]);
    $disk = Storage::fake('snapshots');
    Http::fake(['source.test/*' => Http::response($dump)]);

    $this->artisan('snapshot:sync', ['--no-load' => true])->assertSuccessful();

    $files = $disk->files();
    expect($files)->toHaveCount(1);
    expect($files[0])->toEndWith('.sql')->not->toEndWith('.sql.gz');
    expect($disk->get($files[0]))
        ->not->toContain('\\restrict')
        ->not->toContain('transaction_timeout')
        ->toContain('CREATE TABLE foo');
});

it('names a gzipped download .sql.gz and sanitizes it in place', function () use ($dump): void {
    config([
        'db-snapshot-sync.sync.allowed_environments' => ['testing'],
        'db-snapshot-sync.sanitizer.pgsql.drop_prefixes' => ['\\restrict', 'SET transaction_timeout'],
    ]);
    $disk = Storage::fake('snapshots');
    Http::fake(['source.test/*' => Http::response(gzencode($dump, 9))]);

    $this->artisan('snapshot:sync', ['--no-load' => true])->assertSuccessful();

    $files = $disk->files();
    expect($files)->toHaveCount(1);
    expect($files[0])->toEndWith('.sql.gz');
    expect(gzdecode($disk->get($files[0])))
        ->not->toContain('\\restrict')
        ->toContain('CREATE TABLE foo');
});

it('names a zstd download .sql.zst and sanitizes it in place, still zstd', function () use ($dump): void {
    config([
        'db-snapshot-sync.sync.allowed_environments' => ['testing'],
        'db-snapshot-sync.sanitizer.pgsql.drop_prefixes' => ['\\restrict', 'SET transaction_timeout'],
    ]);
    $disk = Storage::fake('snapshots');
    Http::fake(['source.test/*' => Http::response(zstd($dump))]);

    $this->artisan('snapshot:sync', ['--no-load' => true])->assertSuccessful();

    $files = $disk->files();
    expect($files)->toHaveCount(1);
    expect($files[0])->toEndWith('.sql.zst');
    expect(zstd($disk->get($files[0]), decompress: true))
        ->not->toContain('\\restrict')
        ->toContain('CREATE TABLE foo');
})->skip(fn (): bool => zstdMissing(), 'zstd not available');

it('runs by default in any environment but production', function (string $env): void {
    app()['env'] = $env;

    // An unknown source fails right after the environment guard, so reaching it proves the guard passed.
    $this->artisan('snapshot:sync', ['--source' => 'bogus'])
        ->assertFailed()
        ->expectsOutputToContain('Unknown or unconfigured');
})->with(['local', 'staging', 'uat', 'testing']);

it('refuses production by default', function (): void {
    app()['env'] = 'production';

    $this->artisan('snapshot:sync')
        ->assertFailed()
        ->expectsOutputToContain('snapshot:sync may not run in: production.');
});

it('refuses the environments a project denies', function (): void {
    config(['db-snapshot-sync.sync.denied_environments' => ['production', 'stag*']]);
    app()['env'] = 'staging';

    $this->artisan('snapshot:sync')
        ->assertFailed()
        ->expectsOutputToContain('snapshot:sync may not run in: production, stag*.');
});

it('lets an explicit allow-list decide alone', function (): void {
    config(['db-snapshot-sync.sync.allowed_environments' => ['local', 'development']]);

    $this->artisan('snapshot:sync')
        ->assertFailed()
        ->expectsOutputToContain('snapshot:sync may only run in: local, development.');

    config(['db-snapshot-sync.sync.allowed_environments' => ['production']]);
    app()['env'] = 'production';

    $this->artisan('snapshot:sync', ['--source' => 'bogus'])
        ->assertFailed()
        ->expectsOutputToContain('Unknown or unconfigured');
});

it('reads both lists from comma-separated env vars', function (): void {
    $sync = fn (): array => (require __DIR__.'/../../config/db-snapshot-sync.php')['sync'];

    expect($sync())->toBe(['allowed_environments' => [], 'denied_environments' => ['production']]);

    putenv('DB_SNAPSHOT_SYNC_ALLOWED_ENVIRONMENTS=local, staging');
    putenv('DB_SNAPSHOT_SYNC_DENIED_ENVIRONMENTS=production,uat');

    try {
        expect($sync())->toBe([
            'allowed_environments' => ['local', 'staging'],
            'denied_environments' => ['production', 'uat'],
        ]);
    } finally {
        putenv('DB_SNAPSHOT_SYNC_ALLOWED_ENVIRONMENTS');
        putenv('DB_SNAPSHOT_SYNC_DENIED_ENVIRONMENTS');
    }
});

it('errors on an unknown source', function (): void {
    config(['db-snapshot-sync.sync.allowed_environments' => ['testing']]);

    $this->artisan('snapshot:sync', ['--source' => 'bogus'])
        ->assertFailed()
        ->expectsOutputToContain('Unknown or unconfigured');
});

it('errors when the token is missing', function (): void {
    config([
        'db-snapshot-sync.sync.allowed_environments' => ['testing'],
        'db-snapshot-sync.token' => null,
    ]);

    $this->artisan('snapshot:sync')
        ->assertFailed()
        ->expectsOutputToContain('INTERNAL_API_TOKEN is not set');
});

it('sanitizes a MySQL dump with the MySQL rules via --connection while the default is pgsql', function () use ($mysqlDump): void {
    config(['db-snapshot-sync.sync.allowed_environments' => ['testing']]);
    $disk = Storage::fake('snapshots');
    Http::fake(['source.test/*' => Http::response(gzencode($mysqlDump, 9))]);

    $this->artisan('snapshot:sync', ['--no-load' => true, '--connection' => 'mysql'])
        ->assertSuccessful()
        ->expectsOutputToContain('Target connection: mysql (mysql)');

    $files = $disk->files();
    expect($files)->toHaveCount(1);
    expect($files[0])->toEndWith('.sanitized.sql.gz');
    expect(gzdecode($disk->get($files[0])))
        ->not->toContain('DEFINER=')
        ->toContain('SQL SECURITY INVOKER')
        ->toContain('CREATE TABLE bar');
})->skip(fn (): bool => ! shell_exec('command -v sed'), 'sed not available');

it('loads into the target connection and restores the caller\'s default connection', function () use ($mysqlDump): void {
    config(['db-snapshot-sync.sync.allowed_environments' => ['testing']]);
    Storage::fake('snapshots');
    Http::fake(['source.test/*' => Http::response(gzencode($mysqlDump, 9))]);
    $load = fakeMysqlLoad();

    $this->artisan('snapshot:sync', ['--connection' => 'mysql'])->assertSuccessful();

    expect($load['connection'])->toBe('mysql');
    expect($load['path'])->toEndWith('.sanitized.sql.gz');
    expect(DB::getDefaultConnection())->toBe('pgsql');
})->skip(fn (): bool => ! shell_exec('command -v sed'), 'sed not available');

it('takes the target connection from an array-form source', function () use ($mysqlDump): void {
    config([
        'db-snapshot-sync.sync.allowed_environments' => ['testing'],
        'db-snapshot-sync.sources.production' => ['url' => 'https://source.test', 'connection' => 'mysql'],
    ]);
    Storage::fake('snapshots');
    Http::fake(['source.test/*' => Http::response(gzencode($mysqlDump, 9))]);
    $load = fakeMysqlLoad();

    $this->artisan('snapshot:sync')->assertSuccessful();

    expect($load['connection'])->toBe('mysql');
    expect(DB::getDefaultConnection())->toBe('pgsql');
})->skip(fn (): bool => ! shell_exec('command -v sed'), 'sed not available');

it('lets --connection override the source connection', function () use ($pgDump): void {
    config([
        'db-snapshot-sync.sync.allowed_environments' => ['testing'],
        'db-snapshot-sync.sources.production' => ['url' => 'https://source.test', 'connection' => 'mysql'],
    ]);
    Storage::fake('snapshots');
    Http::fake(['source.test/*' => Http::response($pgDump)]);
    $load = fakePsqlLoad();

    $this->artisan('snapshot:sync', ['--connection' => 'pgsql'])->assertSuccessful();

    expect($load['connection'])->toBe('pgsql');
});

it('keeps a PG to PG sync on the default connection, sanitized in place', function () use ($pgDump): void {
    config([
        'db-snapshot-sync.sync.allowed_environments' => ['testing'],
        'db-snapshot-sync.sanitizer.pgsql.drop_prefixes' => ['\\restrict'],
    ]);
    $disk = Storage::fake('snapshots');
    Http::fake(['source.test/*' => Http::response(gzencode($pgDump, 9))]);
    $snapshotLoad = fakeMysqlLoad();
    $load = fakePsqlLoad();

    $this->artisan('snapshot:sync')
        ->assertSuccessful()
        ->expectsOutputToContain('Target connection: pgsql (pgsql)');

    expect($snapshotLoad->count())->toBe(0);
    expect($load['connection'])->toBe('pgsql');
    expect($load['drop-tables'])->toBeTrue();
    expect(DB::getDefaultConnection())->toBe('pgsql');

    $files = $disk->files();
    expect($files)->toHaveCount(1);
    expect($load['path'])->toBe($disk->path($files[0]));
    expect(gzdecode($disk->get($files[0])))->not->toContain('\\restrict');
});

it('passes drop-tables off through to the psql loader', function () use ($pgDump): void {
    config([
        'db-snapshot-sync.sync.allowed_environments' => ['testing'],
        'db-snapshot-sync.load.drop-tables' => false,
    ]);
    Storage::fake('snapshots');
    Http::fake(['source.test/*' => Http::response($pgDump)]);
    $load = fakePsqlLoad();

    $this->artisan('snapshot:sync')->assertSuccessful();

    expect($load['drop-tables'])->toBeFalse();
});

it('exits non-zero and keeps the download when the psql load fails', function () use ($pgDump): void {
    config(['db-snapshot-sync.sync.allowed_environments' => ['testing']]);
    $disk = Storage::fake('snapshots');
    Http::fake(['source.test/*' => Http::response($pgDump)]);
    fakePsqlLoad(new RuntimeException('psql failed to load; the load was rolled back.'));

    expect(fn () => $this->artisan('snapshot:sync')->run())
        ->toThrow(RuntimeException::class, 'psql failed to load');

    expect($disk->files())->toHaveCount(1);
    expect(DB::getDefaultConnection())->toBe('pgsql');
});

it('refuses a MySQL dump bound for a pgsql connection without sanitizing or loading', function () use ($mysqlDump): void {
    config(['db-snapshot-sync.sync.allowed_environments' => ['testing']]);
    $disk = Storage::fake('snapshots');
    Http::fake(['source.test/*' => Http::response(gzencode($mysqlDump, 9))]);
    $load = fakeMysqlLoad();

    $this->artisan('snapshot:sync')
        ->assertFailed()
        ->expectsOutputToContain('is a MySQL/MariaDB (mysqldump) dump, but connection [pgsql] uses the pgsql driver');

    expect($load->count())->toBe(0);
    expect($disk->files())->toHaveCount(1);
    expect(gzdecode($disk->get($disk->files()[0])))->toContain('DEFINER=');
});

it('refuses a pg_dump bound for a mysql connection', function () use ($pgDump): void {
    config(['db-snapshot-sync.sync.allowed_environments' => ['testing']]);
    Storage::fake('snapshots');
    Http::fake(['source.test/*' => Http::response($pgDump)]);
    $load = fakeMysqlLoad();

    $this->artisan('snapshot:sync', ['--connection' => 'mysql'])
        ->assertFailed()
        ->expectsOutputToContain('is a PostgreSQL (pg_dump) dump, but connection [mysql] uses the mysql driver');

    expect($load->count())->toBe(0);
});

it('errors on an unconfigured connection before downloading', function (): void {
    config(['db-snapshot-sync.sync.allowed_environments' => ['testing']]);
    Http::fake();

    $this->artisan('snapshot:sync', ['--connection' => 'nope'])
        ->assertFailed()
        ->expectsOutputToContain('Database connection [nope] is not configured.');

    Http::assertNothingSent();
});

it('keeps a path on the source base URL', function () use ($pgDump): void {
    config([
        'db-snapshot-sync.sync.allowed_environments' => ['testing'],
        'db-snapshot-sync.sources.production' => ['url' => 'https://source.test/api', 'connection' => 'pgsql'],
    ]);
    Storage::fake('snapshots');
    Http::fake(['source.test/*' => Http::response($pgDump)]);

    $this->artisan('snapshot:sync', ['--no-load' => true])->assertSuccessful();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://source.test/api/internal/snapshots/latest');
});

/**
 * Make `production` an ssh source served by tests/Fixtures/fake-ssh, which streams $payload.
 *
 * @param  array<string, mixed>  $behaviour  exit, stderr, meta ("ok", "none", "short")
 * @return string the fake's working directory (argv.json lands there)
 */
function fakeSshSource(string $payload, array $behaviour = [], array $source = []): string
{
    $dir = sys_get_temp_dir().'/fake-ssh-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("{$dir}/payload", $payload);
    file_put_contents("{$dir}/behaviour.json", json_encode($behaviour));
    putenv("FAKE_SSH_DIR={$dir}");

    config([
        'db-snapshot-sync.sync.allowed_environments' => ['testing'],
        'db-snapshot-sync.token' => null,
        'db-snapshot-sync.ssh.binary' => __DIR__.'/../Fixtures/fake-ssh',
        'db-snapshot-sync.sources.production' => [
            'ssh' => 'prod-box',
            'path' => '/var/www/shop/current',
            'run_as' => 'www-data',
            ...$source,
        ],
    ]);

    return $dir;
}

it('pulls over ssh with no token, verifies the transfer, then sanitizes and loads', function () use ($pgDump): void {
    config(['db-snapshot-sync.sanitizer.pgsql.drop_prefixes' => ['\\restrict']]);
    $disk = Storage::fake('snapshots');
    $dir = fakeSshSource(gzencode($pgDump, 9));
    Http::fake();
    $load = fakePsqlLoad();

    $this->artisan('snapshot:sync')
        ->assertSuccessful()
        ->expectsOutputToContain('Source: ssh prod-box:/var/www/shop/current (as www-data)')
        ->expectsOutputToContain('source: Streaming payload…')
        ->expectsOutputToContain('sha256 verified');

    Http::assertNothingSent();

    $argv = json_decode((string) file_get_contents("{$dir}/argv.json"), true);
    expect($argv)->toContain('prod-box');
    expect(end($argv))->toBe("cd '/var/www/shop/current' && 'sudo' '-n' '-u' 'www-data' 'env' 'HOME=/tmp' 'php' '-d' 'display_errors=stderr' 'artisan' 'snapshot:export' '--no-ansi' '--no-interaction'");

    $files = $disk->files();
    expect($files)->toHaveCount(1);
    expect($files[0])->toEndWith('.sql.gz');
    expect($load['path'])->toBe($disk->path($files[0]));
    expect(gzdecode($disk->get($files[0])))->not->toContain('\\restrict');
});

it('asks the box for a fresh snapshot over ssh', function () use ($pgDump): void {
    Storage::fake('snapshots');
    $dir = fakeSshSource($pgDump);
    fakePsqlLoad();

    $this->artisan('snapshot:sync', ['--fresh' => true])->assertSuccessful();

    $argv = json_decode((string) file_get_contents("{$dir}/argv.json"), true);
    expect(end($argv))->toContain("'snapshot:export' '--fresh'");
});

it('deletes a short transfer and loads nothing', function (string $meta) use ($pgDump): void {
    $disk = Storage::fake('snapshots');
    fakeSshSource($pgDump, ['meta' => $meta]);
    $load = fakePsqlLoad();

    $this->artisan('snapshot:sync')->assertFailed();

    expect($load->count())->toBe(0);
    expect($disk->files())->toBe([]);
})->with(['size mismatch' => 'short', 'no closing line' => 'none']);

it('explains the ssh failures it can recognise', function (array $behaviour, string $message) use ($pgDump): void {
    $disk = Storage::fake('snapshots');
    fakeSshSource($pgDump, $behaviour);
    $load = fakePsqlLoad();

    $this->artisan('snapshot:sync')
        ->assertFailed()
        ->expectsOutputToContain($message);

    expect($load->count())->toBe(0);
    expect($disk->files())->toBe([]);
})->with([
    'no login' => [['exit' => 255, 'stderr' => 'Permission denied (publickey).'], 'ssh could not log in to [prod-box]'],
    'no sudo rule' => [['exit' => 1, 'stderr' => 'sudo: a password is required'], 'cannot run artisan as [www-data] without a password'],
    'old package on the box (artisan errors on stdout)' => [['exit' => 1, 'stdout' => '  ERROR  Command "snapshot:export" is not defined.'], 'Deploy ^1.6 there first'],
    'export failed' => [['exit' => 1, 'stderr' => 'No snapshot on disk [snapshots].'], 'snapshot:export failed on [prod-box] (exit 1)'],
]);

it('refuses an ssh source without a path before connecting', function (): void {
    Storage::fake('snapshots');
    $dir = fakeSshSource('x', source: ['path' => null]);

    $this->artisan('snapshot:sync')
        ->assertFailed()
        ->expectsOutputToContain('sets ssh [prod-box] but no path');

    expect(file_exists("{$dir}/argv.json"))->toBeFalse();
});

it('prefers the ssh host over a url on the same source and warns on an HTTP-only one', function () use ($pgDump): void {
    Storage::fake('snapshots');
    fakeSshSource($pgDump, source: ['url' => 'https://source.test']);
    Http::fake();
    fakePsqlLoad();

    $this->artisan('snapshot:sync')->assertSuccessful()->doesntExpectOutputToContain('deprecated');
    Http::assertNothingSent();

    config([
        'db-snapshot-sync.token' => 'secret-token',
        'db-snapshot-sync.sources.production' => ['ssh' => null, 'url' => 'https://source.test'],
    ]);
    Http::fake(['source.test/*' => Http::response($pgDump)]);

    $this->artisan('snapshot:sync')->assertSuccessful()->expectsOutputToContain('deprecated and removed in 2.0');
});

it('obeys the package-wide prohibit before downloading anything', function (): void {
    config(['db-snapshot-sync.sync.allowed_environments' => ['testing']]);
    Http::fake();
    DbSnapshotSync::prohibitDestructiveCommands();

    $this->artisan('snapshot:sync')
        ->expectsOutputToContain('This command is prohibited from running in this environment.')
        ->assertFailed();

    Http::assertNothingSent();
});
