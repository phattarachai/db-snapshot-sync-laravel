<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Phattarachai\DbSnapshotSyncLaravel\Backup\SnapshotFile;

it('takes the time from the stamp spatie puts in the name, in the app timezone', function (string $path): void {
    config(['app.timezone' => 'Asia/Bangkok']);

    $file = new SnapshotFile($path, 1, Carbon::parse('2026-10-10 03:30:00', 'UTC')->getTimestamp());

    expect($file->takenAt())->toBe(Carbon::parse('2026-10-09 17:34:44', 'Asia/Bangkok')->getTimestamp());
})->with([
    'plain' => ['2026-10-09_17-34-44.sql.gz'],
    'connection prefix' => ['db/daily/pgsql_2026-10-09_17-34-44.sql'],
    'weekly copy' => ['db/weekly/2026-W41_2026-10-09_17-34-44.sql.gz'],
]);

it('falls back to the last-modified time when the name has no valid stamp', function (string $path): void {
    $file = new SnapshotFile($path, 1, 1_760_000_000);

    expect($file->takenAt())->toBe(1_760_000_000);
})->with([
    'named snapshot' => ['before-import.sql.gz'],
    'impossible date' => ['2026-13-45_17-34-44.sql.gz'],
]);
