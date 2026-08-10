<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Phattarachai\DbSnapshotSyncLaravel\Sanitizers\MySqlSanitizer;

beforeEach(function (): void {
    $this->disk = Storage::fake('snapshots');
});

$sed = [
    's/DEFINER=`[^`]+`@`[^`]+`//g',
    's/SQL SECURITY DEFINER/SQL SECURITY INVOKER/g',
];

it('strips DEFINER clauses into a separate sanitized file', function () use ($sed): void {
    $dump = "/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */\nCREATE TABLE bar (id int);\n";
    $this->disk->put('snap.sql', $dump);

    $out = (new MySqlSanitizer($sed))->sanitize($this->disk, 'snap.sql');

    expect($out)->toBe('snap.sanitized.sql.gz');
    expect($this->disk->exists('snap.sql'))->toBeTrue();

    $cleaned = gzdecode($this->disk->get('snap.sanitized.sql.gz'));
    expect($cleaned)
        ->not->toContain('DEFINER=')
        ->toContain('SQL SECURITY INVOKER')
        ->toContain('CREATE TABLE bar');
})->skip(fn (): bool => ! shell_exec('command -v sed'), 'sed not available');

it('reads gzipped input', function () use ($sed): void {
    $this->disk->put('snap.sql.gz', gzencode("DEFINER=`a`@`b` x\nkeep\n", 9));

    $out = (new MySqlSanitizer($sed))->sanitize($this->disk, 'snap.sql.gz');

    expect($out)->toBe('snap.sanitized.sql.gz');
    expect(gzdecode($this->disk->get('snap.sanitized.sql.gz')))
        ->not->toContain('DEFINER=')
        ->toContain('keep');
})->skip(fn (): bool => ! shell_exec('command -v sed'), 'sed not available');
