<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

function datedSnapshot(FilesystemAdapter $disk, string $path, Carbon $at): void
{
    $disk->put($path, 'dump');
    touch($disk->path($path), $at->getTimestamp());
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-03-04 04:05:00', 'UTC'));
    $this->local = Storage::fake('snapshots');

    config([
        'db-snapshot-sync.backup.local_days' => 14,
        'db-snapshot-sync.backup.keep_min' => 3,
    ]);
});

it('deletes snapshots older than local_days, keeping recent, protected and non-snapshot files', function (): void {
    datedSnapshot($this->local, 'recent-1.sql.gz', now()->subDay());
    datedSnapshot($this->local, 'recent-2.sql.gz', now()->subDays(2));
    datedSnapshot($this->local, 'recent-3.sql', now()->subDays(13));
    datedSnapshot($this->local, 'edge.sql.gz', now()->subDays(14)->addMinute());
    datedSnapshot($this->local, 'old.sql.gz', now()->subDays(14)->subMinute());
    datedSnapshot($this->local, 'testing.sql', now()->subYear());
    datedSnapshot($this->local, 'notes.txt', now()->subYear());

    $this->artisan('snapshot:prune')
        ->expectsOutputToContain('Deleted old.sql.gz')
        ->expectsOutputToContain('Pruned 1 snapshot(s).')
        ->assertSuccessful();

    expect($this->local->files())->toEqualCanonicalizing([
        'recent-1.sql.gz', 'recent-2.sql.gz', 'recent-3.sql', 'edge.sql.gz', 'testing.sql', 'notes.txt',
    ]);
});

it('never prunes below keep_min, so failed nightlies cannot empty the box', function (): void {
    foreach (range(20, 25) as $days) {
        datedSnapshot($this->local, "old-{$days}.sql.gz", now()->subDays($days));
    }

    $this->artisan('snapshot:prune')->assertSuccessful();

    expect($this->local->files())->toEqualCanonicalizing(['old-20.sql.gz', 'old-21.sql.gz', 'old-22.sql.gz']);
});

it('does not count a rejected file towards keep_min', function (): void {
    datedSnapshot($this->local, 'sync.sanitized.sql.gz', now()->subDays(20));
    datedSnapshot($this->local, 'old-21.sql.gz', now()->subDays(21));
    datedSnapshot($this->local, 'old-22.sql.gz', now()->subDays(22));
    datedSnapshot($this->local, 'old-23.sql.gz', now()->subDays(23));
    datedSnapshot($this->local, 'old-24.sql.gz', now()->subDays(24));

    $this->artisan('snapshot:prune')->assertSuccessful();

    expect($this->local->files())->toEqualCanonicalizing(['old-21.sql.gz', 'old-22.sql.gz', 'old-23.sql.gz']);
});

it('takes --days over local_days', function (): void {
    config(['db-snapshot-sync.backup.keep_min' => 0]);

    datedSnapshot($this->local, 'two-days.sql.gz', now()->subDays(2));
    datedSnapshot($this->local, 'today.sql.gz', now()->subHour());

    $this->artisan('snapshot:prune', ['--days' => 1])->assertSuccessful();

    expect($this->local->files())->toBe(['today.sql.gz']);
});

it('lists without deleting on --dry-run', function (): void {
    config(['db-snapshot-sync.backup.keep_min' => 0]);

    datedSnapshot($this->local, 'old.sql.gz', now()->subDays(30));

    $this->artisan('snapshot:prune', ['--dry-run' => true])
        ->expectsOutputToContain('Would delete old.sql.gz')
        ->assertSuccessful();

    expect($this->local->exists('old.sql.gz'))->toBeTrue();
});

it('rejects a --days that is not a positive whole number', function (string $days): void {
    $this->artisan('snapshot:prune', ['--days' => $days])->assertFailed();
})->with(['0', '-3', 'two']);
