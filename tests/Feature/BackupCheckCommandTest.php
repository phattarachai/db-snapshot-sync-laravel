<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
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
