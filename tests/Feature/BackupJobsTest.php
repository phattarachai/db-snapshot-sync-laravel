<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Phattarachai\DbSnapshotSyncLaravel\Events\SnapshotBackupStale;
use Phattarachai\DbSnapshotSyncLaravel\Jobs\BackupSnapshots;
use Phattarachai\DbSnapshotSyncLaravel\Jobs\CheckSnapshotBackup;
use Phattarachai\DbSnapshotSyncLaravel\Jobs\PruneSnapshots;
use Phattarachai\DbSnapshotSyncLaravel\Jobs\RunRestoreDrill;

beforeEach(function (): void {
    $this->local = Storage::fake('snapshots');
    $this->spaces = Storage::fake('spaces');
    config(['db-snapshot-sync.backup.disks' => ['spaces']]);
});

it('goes on the configured backup queue', function (string $job): void {
    config(['db-snapshot-sync.backup.queue' => 'backups']);

    expect((new $job)->queue)->toBe('backups');
})->with([BackupSnapshots::class, PruneSnapshots::class, CheckSnapshotBackup::class, RunRestoreDrill::class]);

it('backs up when run as a job', function (): void {
    $this->local->put('nightly.sql.gz', 'dump');
    touch($this->local->path('nightly.sql.gz'), Carbon::now()->subMinutes(5)->getTimestamp());

    dispatch_sync(new BackupSnapshots);

    expect($this->spaces->exists('db/daily/nightly.sql.gz'))->toBeTrue();
});

it('fails the backup job when a target failed', function (): void {
    config(['db-snapshot-sync.backup.disks' => ['broken']]);
    $broken = Mockery::mock(Filesystem::class);
    $broken->shouldReceive('exists', 'files')->andThrow(new RuntimeException('Access Denied'));
    Storage::set('broken', $broken);

    $this->local->put('nightly.sql.gz', 'dump');
    touch($this->local->path('nightly.sql.gz'), Carbon::now()->subMinutes(5)->getTimestamp());

    expect(fn () => dispatch_sync(new BackupSnapshots))->toThrow(RuntimeException::class, '[broken] Access Denied');
});

it('prunes when run as a job', function (): void {
    config(['db-snapshot-sync.backup.keep_min' => 0]);
    $this->local->put('old.sql.gz', 'dump');
    touch($this->local->path('old.sql.gz'), Carbon::now()->subDays(30)->getTimestamp());

    dispatch_sync(new PruneSnapshots(days: 7));

    expect($this->local->exists('old.sql.gz'))->toBeFalse();
});

it('reports a stale target through the event, not by failing the check job', function (): void {
    Event::fake([SnapshotBackupStale::class]);

    dispatch_sync(new CheckSnapshotBackup);

    Event::assertDispatched(SnapshotBackupStale::class);
});

it('fails the drill job when the drill fails', function (): void {
    expect(fn () => dispatch_sync(new RunRestoreDrill(disk: 'spaces')))->toThrow(RuntimeException::class, 'holds no daily copy');
});
