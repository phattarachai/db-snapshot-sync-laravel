<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Phattarachai\DbSnapshotSyncLaravel\Events\SnapshotBackupChecked;
use Phattarachai\DbSnapshotSyncLaravel\Events\SnapshotBackupHealthy;
use Phattarachai\DbSnapshotSyncLaravel\Events\SnapshotBackupStale;

/*
 * Against the real dispatcher, not Event::fake(): Laravel resolves listeners by
 * the event's class and its interfaces, never its parent class, and this is
 * the contract a host's listener relies on.
 */
it('delivers both outcomes to a listener on the SnapshotBackupChecked interface', function (): void {
    $spaces = Storage::fake('spaces');
    Storage::fake('nas');
    config(['db-snapshot-sync.backup.disks' => ['spaces', 'nas']]);

    $spaces->put('db/daily/fresh.sql.gz', 'dump');
    touch($spaces->path('db/daily/fresh.sql.gz'), now()->subHour()->getTimestamp());

    $received = [];
    Event::listen(SnapshotBackupChecked::class, function (SnapshotBackupChecked $event) use (&$received): void {
        $received[$event->disk] = $event::class;
    });

    $this->artisan('snapshot:backup-check')->assertFailed();

    expect($received)->toBe([
        'spaces' => SnapshotBackupHealthy::class,
        'nas' => SnapshotBackupStale::class,
    ]);
});
