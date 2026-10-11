<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Phattarachai\DbSnapshotSyncLaravel\Snapshots\Snapshot;
use Spatie\DbSnapshots\DbSnapshotsServiceProvider;
use Spatie\DbSnapshots\SnapshotRepository;

/*
 * spatie's own commands, end to end on SQLite: snapshot:create, :load and
 * :cleanup through the package's factory and repository. Needs the sqlite3
 * and zstd binaries.
 */

beforeEach(function (): void {
    if (trim((string) shell_exec('command -v sqlite3')) === '') {
        $this->markTestSkipped('sqlite3 is not on PATH.');
    }

    $this->database = tempnam(sys_get_temp_dir(), 'snapshot-sqlite-');

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => $this->database, 'prefix' => ''],
        'db-snapshots.disk' => 'snapshots',
        'db-snapshots.temporary_directory_path' => sys_get_temp_dir().'/db-snapshots-temp',
    ]);

    $this->disk = Storage::fake('snapshots');
    config(['filesystems.disks.snapshots' => ['driver' => 'local', 'root' => $this->disk->path('')]]);

    // Registered after boot, so spatie binds its repository after the package
    // has: the package's must still be the one resolved.
    $this->app->register(DbSnapshotsServiceProvider::class);

    DB::connection('sqlite')->getSchemaBuilder()->create('users', fn ($table) => $table->string('name'));
    DB::connection('sqlite')->table('users')->insert([['name' => "it's here"], ['name' => 'C:\\']]);
});

afterEach(function (): void {
    if (isset($this->database) && is_file($this->database)) {
        unlink($this->database);
    }
});

it('creates a .sql.zst with --compress when the format is zstd', function (): void {
    config(['db-snapshot-sync.compression.format' => 'zstd']);

    $this->artisan('snapshot:create', ['name' => 'snap', '--compress' => true])->assertSuccessful();

    expect($this->disk->files())->toBe(['snap.sql.zst'])
        ->and(zstd($this->disk->get('snap.sql.zst'), decompress: true))->toContain('CREATE TABLE')->toContain("it''s here");
})->skip(fn (): bool => zstdMissing(), 'zstd not available');

it('still creates a .sql.gz by default', function (): void {
    $this->artisan('snapshot:create', ['name' => 'snap', '--compress' => true])->assertSuccessful();

    expect($this->disk->files())->toBe(['snap.sql.gz'])
        ->and(gzdecode($this->disk->get('snap.sql.gz')))->toContain('CREATE TABLE');
});

it('loads a .sql.zst back, streamed or whole', function (bool $stream): void {
    config(['db-snapshot-sync.compression.format' => 'zstd']);
    $this->artisan('snapshot:create', ['name' => 'snap', '--compress' => true])->assertSuccessful();
    expect($this->disk->files())->toBe(['snap.sql.zst']);
    DB::connection('sqlite')->table('users')->delete();

    $this->artisan('snapshot:load', ['name' => 'snap', '--connection' => 'sqlite', '--force' => true, '--stream' => $stream])
        ->expectsOutputToContain('Snapshot `snap` loaded')
        ->assertSuccessful();

    expect(DB::connection('sqlite')->table('users')->orderBy('name')->pluck('name')->all())->toBe(['C:\\', "it's here"]);
})->with(['streamed' => true, 'whole' => false])->skip(fn (): bool => zstdMissing(), 'zstd not available');

it('lists .sql.zst by its spatie name, and cleans it up', function (): void {
    $this->disk->put('2026-10-10_03-00-00.sql.zst', zstd('x'));
    touch($this->disk->path('2026-10-10_03-00-00.sql.zst'), time() - 120);
    $this->disk->put('2026-10-11_03-00-00.sql.gz', gzencode('x', 9));
    touch($this->disk->path('2026-10-11_03-00-00.sql.gz'), time() - 60);
    $this->disk->put('2026-10-12_03-00-00.sql.zst', zstd('x'));

    $snapshots = app(SnapshotRepository::class)->getAll();

    expect($snapshots->map(fn (Snapshot $snapshot): string => $snapshot->name)->values()->all())
        ->toBe(['2026-10-12_03-00-00', '2026-10-11_03-00-00', '2026-10-10_03-00-00']);

    $this->artisan('snapshot:cleanup', ['--keep' => 2])->assertSuccessful();

    expect($this->disk->files())->toBe(['2026-10-11_03-00-00.sql.gz', '2026-10-12_03-00-00.sql.zst']);
})->skip(fn (): bool => zstdMissing(), 'zstd not available');
