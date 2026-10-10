<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Write a snapshot onto a fake disk and date it.
 */
function putSnapshot(FilesystemAdapter $disk, string $path, Carbon $at, string $body = 'dump'): void
{
    $disk->put($path, $body);
    touch($disk->path($path), $at->getTimestamp());
}

/**
 * A target disk whose every call throws, like an unreachable bucket.
 */
function unreachableDisk(): Filesystem
{
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists', 'size', 'files', 'writeStream', 'readStream', 'copy', 'lastModified', 'delete', 'setVisibility')
        ->andThrow(new RuntimeException('Could not resolve host'));

    return $disk;
}

beforeEach(function (): void {
    // Wednesday of ISO week 2026-W10 (Mon 2 Mar – Sun 8 Mar).
    $this->travelTo(Carbon::parse('2026-03-04 04:03:00', 'UTC'));

    $this->local = Storage::fake('snapshots');
    // The target's own default is public: the backup must not inherit it.
    $this->spaces = Storage::fake('spaces', ['visibility' => 'public']);

    config([
        'db-snapshot-sync.backup.disks' => ['spaces'],
        'db-snapshot-sync.backup.path' => 'db',
    ]);
});

it('uploads every recent snapshot under daily/, privately, skipping protected, rejected and stale-by-age files', function (): void {
    putSnapshot($this->local, 'nightly-0304.sql.gz', now()->subMinutes(3), 'tonight');
    putSnapshot($this->local, 'nightly-0303.sql.gz', now()->subDay(), 'last night');
    putSnapshot($this->local, 'deploy-0302.sql', now()->subDays(2), 'plain dump');
    putSnapshot($this->local, 'testing.sql', now()->subDays(1));
    putSnapshot($this->local, 'sync_x.sanitized.sql.gz', now()->subDays(1));
    putSnapshot($this->local, 'ancient.sql.gz', now()->subDays(15));
    putSnapshot($this->local, 'notes.txt', now()->subDay());

    $this->artisan('snapshot:backup')->assertSuccessful();

    expect($this->spaces->files('db/daily'))->toEqualCanonicalizing([
        'db/daily/nightly-0304.sql.gz',
        'db/daily/nightly-0303.sql.gz',
        'db/daily/deploy-0302.sql',
    ]);
    expect($this->spaces->get('db/daily/nightly-0303.sql.gz'))->toBe('last night');
    expect($this->spaces->getVisibility('db/daily/nightly-0304.sql.gz'))->toBe('private');
});

it('does not upload a snapshot that may still be mid-copy onto the snapshots disk', function (): void {
    putSnapshot($this->local, 'being-written.sql.gz', now()->subSeconds(10));

    $this->artisan('snapshot:backup')->assertSuccessful();

    expect($this->spaces->exists('db/daily/being-written.sql.gz'))->toBeFalse();
});

it('catches up a missed night, skips copies already there, and replaces a short copy', function (): void {
    putSnapshot($this->local, 'missed.sql.gz', now()->subDays(2), 'missed night');
    putSnapshot($this->local, 'done.sql.gz', now()->subDay(), 'already up');
    putSnapshot($this->local, 'short.sql.gz', now()->subHours(5), 'the whole file');

    $this->spaces->put('db/daily/done.sql.gz', 'already up');
    $this->spaces->put('db/daily/short.sql.gz', 'the who');

    $this->artisan('snapshot:backup')
        ->expectsOutputToContain('[spaces] uploaded db/daily/missed.sql.gz')
        ->expectsOutputToContain('[spaces] uploaded db/daily/short.sql.gz')
        ->doesntExpectOutputToContain('uploaded db/daily/done.sql.gz')
        ->assertSuccessful();

    expect($this->spaces->get('db/daily/short.sql.gz'))->toBe('the whole file');
    expect($this->spaces->get('db/daily/missed.sql.gz'))->toBe('missed night');
});

it('backs up to every target and exits non-zero when one fails', function (): void {
    config(['db-snapshot-sync.backup.disks' => ['broken', 'spaces']]);
    Storage::set('broken', unreachableDisk());

    putSnapshot($this->local, 'nightly.sql.gz', now()->subMinutes(3));

    $this->artisan('snapshot:backup')
        ->expectsOutputToContain('[broken] FAILED: Could not resolve host')
        ->expectsOutputToContain('[spaces] ok: 1 uploaded')
        ->assertFailed();

    expect($this->spaces->exists('db/daily/nightly.sql.gz'))->toBeTrue();
});

it('deletes a copy whose size does not match after upload and fails the target', function (): void {
    putSnapshot($this->local, 'nightly.sql.gz', now()->subMinutes(3), '12345');

    $short = Mockery::mock(Filesystem::class);
    $short->shouldReceive('exists')->andReturnFalse();
    $short->shouldReceive('writeStream')->once()->with('db/daily/nightly.sql.gz', Mockery::type('resource'), ['visibility' => 'private'])->andReturnTrue();
    $short->shouldReceive('size')->with('db/daily/nightly.sql.gz')->andReturn(3);
    $short->shouldReceive('delete')->once()->with('db/daily/nightly.sql.gz')->andReturnTrue();
    Storage::set('spaces', $short);

    $this->artisan('snapshot:backup')
        ->expectsOutputToContain('is 3 bytes on the target, expected 5; the partial copy was deleted')
        ->assertFailed();
});

it('copies the newest snapshot of each ISO week into weekly/ and replaces it when a newer one lands', function (): void {
    putSnapshot($this->local, 'tue-w10.sql.gz', Carbon::parse('2026-03-03 04:00', 'UTC'), 'tue');
    putSnapshot($this->local, 'mon-w10.sql.gz', Carbon::parse('2026-03-02 04:00', 'UTC'), 'mon');
    putSnapshot($this->local, 'sun-w09.sql.gz', Carbon::parse('2026-03-01 04:00', 'UTC'), 'sun');
    putSnapshot($this->local, 'fri-w09.sql.gz', Carbon::parse('2026-02-27 04:00', 'UTC'), 'fri');

    $this->artisan('snapshot:backup')->assertSuccessful();

    expect($this->spaces->files('db/weekly'))->toEqualCanonicalizing([
        'db/weekly/2026-W10_tue-w10.sql.gz',
        'db/weekly/2026-W09_sun-w09.sql.gz',
    ]);
    expect($this->spaces->getVisibility('db/weekly/2026-W10_tue-w10.sql.gz'))->toBe('private');

    putSnapshot($this->local, 'wed-w10.sql.gz', now()->subMinutes(3), 'wed');

    $this->artisan('snapshot:backup')->assertSuccessful();

    expect($this->spaces->files('db/weekly'))->toEqualCanonicalizing([
        'db/weekly/2026-W10_wed-w10.sql.gz',
        'db/weekly/2026-W09_sun-w09.sql.gz',
    ]);
    expect($this->spaces->get('db/weekly/2026-W10_wed-w10.sql.gz'))->toBe('wed');
});

it('uploads a weekly copy straight from local when its daily copy has aged out', function (): void {
    config(['db-snapshot-sync.backup.daily_days' => 2, 'db-snapshot-sync.backup.keep_min' => 0]);

    putSnapshot($this->local, 'fri-w09.sql.gz', Carbon::parse('2026-02-27 04:00', 'UTC'), 'fri');

    $this->artisan('snapshot:backup')->assertSuccessful();

    expect($this->spaces->exists('db/daily/fri-w09.sql.gz'))->toBeFalse();
    expect($this->spaces->get('db/weekly/2026-W09_fri-w09.sql.gz'))->toBe('fri');
});

it('leaves weeks outside weekly_weeks out of weekly/', function (): void {
    config(['db-snapshot-sync.backup.weekly_weeks' => 2]);

    putSnapshot($this->local, 'w10.sql.gz', Carbon::parse('2026-03-02 04:00', 'UTC'));
    putSnapshot($this->local, 'w09.sql.gz', Carbon::parse('2026-02-23 04:00', 'UTC'));
    putSnapshot($this->local, 'w08.sql.gz', Carbon::parse('2026-02-22 04:00', 'UTC'));

    $this->artisan('snapshot:backup')->assertSuccessful();

    expect($this->spaces->files('db/weekly'))->toEqualCanonicalizing([
        'db/weekly/2026-W10_w10.sql.gz',
        'db/weekly/2026-W09_w09.sql.gz',
    ]);
});

it('prunes daily copies by age on the target, never below keep_min', function (): void {
    config(['db-snapshot-sync.backup.keep_min' => 2, 'db-snapshot-sync.backup.daily_days' => 14]);

    putSnapshot($this->spaces, 'db/daily/a.sql.gz', now()->subDays(13));
    putSnapshot($this->spaces, 'db/daily/b.sql.gz', now()->subDays(15));
    putSnapshot($this->spaces, 'db/daily/c.sql.gz', now()->subDays(20));
    putSnapshot($this->spaces, 'db/daily/d.sql.gz', now()->subDays(30));
    putSnapshot($this->spaces, 'db/daily/readme.txt', now()->subDays(30));

    $this->artisan('snapshot:backup')->assertSuccessful();

    // a is in the window; b is out of it but one of the newest two; c and d go.
    expect($this->spaces->files('db/daily'))->toEqualCanonicalizing([
        'db/daily/a.sql.gz',
        'db/daily/b.sql.gz',
        'db/daily/readme.txt',
    ]);
});

it('keeps keep_min daily copies even after every nightly has failed for weeks', function (): void {
    config(['db-snapshot-sync.backup.keep_min' => 3]);

    foreach (range(20, 25) as $days) {
        putSnapshot($this->spaces, "db/daily/old-{$days}.sql.gz", now()->subDays($days));
    }

    $this->artisan('snapshot:backup')->assertSuccessful();

    expect($this->spaces->files('db/daily'))->toEqualCanonicalizing([
        'db/daily/old-20.sql.gz',
        'db/daily/old-21.sql.gz',
        'db/daily/old-22.sql.gz',
    ]);
});

it('prunes weekly copies by the week in their name', function (): void {
    config(['db-snapshot-sync.backup.weekly_weeks' => 8, 'db-snapshot-sync.backup.keep_min' => 1]);

    // The window is 2026-W03 (Mon 12 Jan) .. 2026-W10.
    $this->spaces->put('db/weekly/2026-W10_now.sql.gz', 'x');
    $this->spaces->put('db/weekly/2026-W03_edge.sql.gz', 'x');
    $this->spaces->put('db/weekly/2026-W02_gone.sql.gz', 'x');
    $this->spaces->put('db/weekly/2025-W40_gone.sql.gz', 'x');
    $this->spaces->put('db/weekly/hand-placed.sql.gz', 'x');

    $this->artisan('snapshot:backup')->assertSuccessful();

    expect($this->spaces->files('db/weekly'))->toEqualCanonicalizing([
        'db/weekly/2026-W10_now.sql.gz',
        'db/weekly/2026-W03_edge.sql.gz',
        'db/weekly/hand-placed.sql.gz',
    ]);
});

it('fails when no target disk is configured', function (): void {
    config(['db-snapshot-sync.backup.disks' => []]);

    $this->artisan('snapshot:backup')
        ->expectsOutputToContain('No backup target disks are configured')
        ->assertFailed();
});
