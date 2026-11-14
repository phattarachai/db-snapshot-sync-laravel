<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Phattarachai\DbSnapshotSyncLaravel\Transfer\ExportResult;
use Phattarachai\DbSnapshotSyncLaravel\Transfer\ExportStream;

/**
 * Point snapshot:export's stdout at a temp file and report it as a pipe, or as a terminal.
 */
function captureExport(bool $terminal = false): string
{
    $path = tempnam(sys_get_temp_dir(), 'export');

    app()->instance(ExportStream::class, new class($path, $terminal) extends ExportStream
    {
        public function __construct(private readonly string $path, private readonly bool $terminal) {}

        public function open()
        {
            return fopen($this->path, 'wb');
        }

        public function isTerminal(): bool
        {
            return $this->terminal;
        }
    });

    return $path;
}

/**
 * Run snapshot:export and return its stderr (the command's console output) and the streamed bytes.
 *
 * @param  array<string, mixed>  $options
 * @return array{exit: int, stderr: string, stdout: string}
 */
function runExport(array $options = [], bool $terminal = false): array
{
    $stdout = captureExport($terminal);
    $exit = Artisan::call('snapshot:export', $options);

    return ['exit' => $exit, 'stderr' => Artisan::output(), 'stdout' => (string) file_get_contents($stdout)];
}

beforeEach(function (): void {
    $this->disk = Storage::fake('snapshots');
});

it('streams the newest snapshot and closes with its size and sha256', function (): void {
    $this->disk->put('older.sql.gz', gzencode('old', 9));
    touch($this->disk->path('older.sql.gz'), time() - 60);
    $this->disk->put('newer.sql.gz', $bytes = gzencode('new', 9));

    $run = runExport();

    expect($run['exit'])->toBe(0);
    expect($run['stdout'])->toBe($bytes);

    $lines = array_values(array_filter(explode("\n", $run['stderr'])));
    expect(ExportResult::fromLine((string) end($lines)))->toEqual(new ExportResult('newer.sql.gz', strlen($bytes), hash('sha256', $bytes)));
});

it('never sends a rejected file', function (): void {
    config(['db-snapshot-sync.export.reject' => ['.sanitized.']]);
    $this->disk->put('real.sql.gz', $bytes = gzencode('real', 9));
    touch($this->disk->path('real.sql.gz'), time() - 60);
    $this->disk->put('real.sanitized.sql.gz', gzencode('half', 9));

    expect(runExport()['stdout'])->toBe($bytes);
});

it('builds a fresh snapshot, streams it, and leaves nothing behind', function (): void {
    $this->disk->put('nightly.sql.gz', gzencode('nightly', 9));
    Artisan::command('snapshot:create {name} {--compress}', function (string $name): void {
        Storage::disk('snapshots')->put("{$name}.sql.gz", gzencode('fresh', 9));
    });

    $run = runExport(['--fresh' => true]);

    expect($run['exit'])->toBe(0);
    expect(gzdecode($run['stdout']))->toBe('fresh');
    expect($this->disk->files())->toBe(['nightly.sql.gz']);
});

it('streams from another disk by pattern', function (): void {
    $other = Storage::fake('snapshots-other');
    $other->put('app_2026-01-01.dump', 'custom-format dump');
    $other->put('app_2026-01-01-raw.dump', 'raw');
    touch($other->path('app_2026-01-01-raw.dump'), time() - 60);
    $other->put('notes.txt', 'x');

    expect(runExport(['--disk' => 'snapshots-other', '--pattern' => 'app_*.dump'])['stdout'])->toBe('custom-format dump');
});

it('refuses to write a snapshot to a terminal', function (): void {
    $this->disk->put('a.sql.gz', gzencode('x', 9));

    $run = runExport(terminal: true);

    expect($run['exit'])->toBe(1);
    expect($run['stdout'])->toBe('');
    expect($run['stderr'])->toContain('writes a binary snapshot to stdout');
});

it('fails when the disk holds no snapshot', function (): void {
    $run = runExport();

    expect($run['exit'])->toBe(1);
    expect($run['stderr'])->toContain('No snapshot on disk [snapshots]');
});

it('refuses --fresh together with another disk', function (): void {
    expect(runExport(['--fresh' => true, '--disk' => 'other'])['stderr'])->toContain('cannot be combined with --disk');
});
