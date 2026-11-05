<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Phattarachai\DbSnapshotSyncLaravel\Events\SnapshotBackupHealthy;
use Phattarachai\DbSnapshotSyncLaravel\Events\SnapshotBackupStale;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-03-04 10:00:00', 'UTC'));
    Event::fake([SnapshotBackupStale::class, SnapshotBackupHealthy::class]);

    $this->spaces = Storage::fake('spaces');
    $this->nas = Storage::fake('nas');

    config([
        'db-snapshot-sync.backup.disks' => ['spaces', 'nas'],
        'db-snapshot-sync.backup.stale_after_hours' => 26,
    ]);
});

function landed(FilesystemAdapter $disk, string $path, Carbon $at): void
{
    $disk->put($path, 'dump');
    touch($disk->path($path), $at->getTimestamp());
}

/**
 * Makes $name a configured disk where each resolved instance answers one listing for
 * good, as an adapter that memoizes requests does, so a new answer needs a new instance.
 *
 * @param  list<array<string, Carbon>|Throwable>  $reads  one per instance: path => taken at, or the failure
 */
function diskReading(string $name, array $reads): void
{
    config(["filesystems.disks.{$name}" => ['driver' => "reading-{$name}"]]);
    Storage::forgetDisk($name);

    Storage::extend("reading-{$name}", function () use (&$reads): Filesystem {
        $read = array_shift($reads) ?? throw new LogicException('Read more often than expected');
        $disk = Mockery::mock(Filesystem::class);

        if ($read instanceof Throwable) {
            $disk->shouldReceive('files')->andThrow($read);

            return $disk;
        }

        $disk->shouldReceive('files')->andReturn(array_keys($read));
        $disk->shouldReceive('size')->andReturn(4);
        $disk->shouldReceive('lastModified')->andReturnUsing(fn (string $path): int => $read[$path]->getTimestamp());

        return $disk;
    });
}

it('reports each target healthy with its newest daily copy and age', function (): void {
    landed($this->spaces, 'db/daily/older.sql.gz', now()->subDays(2));
    landed($this->spaces, 'db/daily/newest.sql.gz', now()->subHours(6));
    landed($this->spaces, 'db/weekly/2026-W10_newer-but-weekly.sql.gz', now()->subMinute());
    landed($this->nas, 'db/daily/newest.sql.gz', now()->subHours(26));

    $this->artisan('snapshot:backup-check')
        ->expectsOutputToContain('OK [spaces] newest db/daily/newest.sql.gz, 6h old')
        ->assertSuccessful();

    Event::assertDispatched(SnapshotBackupHealthy::class, fn (SnapshotBackupHealthy $e): bool => $e->disk === 'spaces'
        && $e->newest === 'db/daily/newest.sql.gz'
        && $e->ageSeconds === 6 * 3600
        && $e->ageHours() === 6.0
        && $e->newestAt?->equalTo(now()->subHours(6)) === true);
    Event::assertDispatched(SnapshotBackupHealthy::class, fn (SnapshotBackupHealthy $e): bool => $e->disk === 'nas');
    Event::assertNotDispatched(SnapshotBackupStale::class);
});

it('takes the newest copy by when it was taken, not by when it was uploaded', function (): void {
    // A first catch-up run uploaded newest first, so the oldest snapshot landed last.
    landed($this->spaces, 'db/daily/2026-03-04_03-30-00.sql.gz', now()->subHours(2));
    landed($this->spaces, 'db/daily/2026-03-02_17-34-44.sql.gz', now()->subHours(2)->addSeconds(30));
    landed($this->nas, 'db/daily/2026-03-04_03-30-00.sql.gz', now()->subHour());

    $this->artisan('snapshot:backup-check')
        ->expectsOutputToContain('OK [spaces] newest db/daily/2026-03-04_03-30-00.sql.gz, 6.5h old')
        ->assertSuccessful();

    Event::assertDispatched(SnapshotBackupHealthy::class, fn (SnapshotBackupHealthy $e): bool => $e->disk === 'spaces'
        && $e->newest === 'db/daily/2026-03-04_03-30-00.sql.gz'
        && $e->ageSeconds === (int) (6.5 * 3600));
});

it('reports a target stale past stale_after_hours and exits non-zero', function (): void {
    landed($this->spaces, 'db/daily/fresh.sql.gz', now()->subHours(2));
    landed($this->nas, 'db/daily/old.sql.gz', now()->subHours(26)->subSecond());

    $this->artisan('snapshot:backup-check')
        ->expectsOutputToContain('STALE [nas] newest db/daily/old.sql.gz')
        ->assertFailed();

    Event::assertDispatched(SnapshotBackupStale::class, fn (SnapshotBackupStale $e): bool => $e->disk === 'nas'
        && $e->newest === 'db/daily/old.sql.gz'
        && $e->ageSeconds === 26 * 3600 + 1
        && $e->error === null);
    Event::assertDispatched(SnapshotBackupHealthy::class, fn (SnapshotBackupHealthy $e): bool => $e->disk === 'spaces');
});

it('reports an empty target stale', function (): void {
    landed($this->spaces, 'db/daily/fresh.sql.gz', now()->subHour());

    $this->artisan('snapshot:backup-check')
        ->expectsOutputToContain('STALE [nas] holds no daily copy')
        ->assertFailed();

    Event::assertDispatched(SnapshotBackupStale::class, fn (SnapshotBackupStale $e): bool => $e->disk === 'nas' && $e->newest === null && $e->ageSeconds === null);
});

it('reports a fresh Google Drive-like target with no daily folder as holding no copy, not unreadable', function (): void {
    landed($this->spaces, 'db/daily/fresh.sql.gz', now()->subHour());
    driveLikeDisk('nas');

    $this->artisan('snapshot:backup-check')
        ->expectsOutputToContain('STALE [nas] holds no daily copy')
        ->assertFailed();

    Event::assertDispatched(SnapshotBackupStale::class, fn (SnapshotBackupStale $e): bool => $e->disk === 'nas' && $e->newest === null && $e->error === null);
});

it('reports an unreachable target stale with the reason', function (): void {
    landed($this->spaces, 'db/daily/fresh.sql.gz', now()->subHour());

    $broken = Mockery::mock(Filesystem::class);
    $broken->shouldReceive('files')->andThrow(new RuntimeException('Connection timed out'));
    Storage::set('nas', $broken);

    $this->artisan('snapshot:backup-check')
        ->expectsOutputToContain('STALE [nas] unreadable: Connection timed out')
        ->assertFailed();

    Event::assertDispatched(SnapshotBackupStale::class, fn (SnapshotBackupStale $e): bool => $e->disk === 'nas' && $e->error === 'Connection timed out');
});

it('reads a target again when its listing comes back empty, so a blip stays healthy', function (): void {
    landed($this->spaces, 'db/daily/fresh.sql.gz', now()->subHour());
    diskReading('nas', [[], ['db/daily/fresh.sql.gz' => now()->subHours(5)]]);

    $this->artisan('snapshot:backup-check')->assertSuccessful();

    Event::assertDispatched(SnapshotBackupHealthy::class, fn (SnapshotBackupHealthy $e): bool => $e->disk === 'nas' && $e->newest === 'db/daily/fresh.sql.gz');
    Event::assertNotDispatched(SnapshotBackupStale::class);
    Sleep::assertSleptTimes(1);
});

it('reads a target again when its listing throws, so a blip stays healthy', function (): void {
    landed($this->spaces, 'db/daily/fresh.sql.gz', now()->subHour());
    diskReading('nas', [new RuntimeException('Backend Error'), ['db/daily/fresh.sql.gz' => now()->subHours(5)]]);

    $this->artisan('snapshot:backup-check')->assertSuccessful();

    Event::assertNotDispatched(SnapshotBackupStale::class);
});

it('reports a target stale once every read comes back empty', function (): void {
    landed($this->spaces, 'db/daily/fresh.sql.gz', now()->subHour());
    diskReading('nas', [[], [], []]);

    $this->artisan('snapshot:backup-check')
        ->expectsOutputToContain('STALE [nas] holds no daily copy')
        ->assertFailed();

    Event::assertDispatched(SnapshotBackupStale::class, fn (SnapshotBackupStale $e): bool => $e->disk === 'nas' && $e->newest === null && $e->error === null);
    Sleep::assertSleptTimes(2);
});

it('reports the last failure once every read throws', function (): void {
    landed($this->spaces, 'db/daily/fresh.sql.gz', now()->subHour());
    diskReading('nas', [new RuntimeException('first'), new RuntimeException('second'), new RuntimeException('last')]);

    $this->artisan('snapshot:backup-check')
        ->expectsOutputToContain('STALE [nas] unreadable: last')
        ->assertFailed();
});

it('does not read again when the newest copy is simply old', function (): void {
    diskReading('spaces', [['db/daily/fresh.sql.gz' => now()->subHour()]]);
    diskReading('nas', [['db/daily/old.sql.gz' => now()->subDays(3)]]);

    $this->artisan('snapshot:backup-check')
        ->expectsOutputToContain('STALE [nas] newest db/daily/old.sql.gz')
        ->assertFailed();

    Sleep::assertNeverSlept();
});
