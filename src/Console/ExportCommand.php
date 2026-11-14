<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Laravel\Pulse\Facades\Pulse;
use Phattarachai\DbSnapshotSyncLaravel\Support\SnapshotCandidates;
use Phattarachai\DbSnapshotSyncLaravel\Support\SyncSnapshotBuilder;
use Phattarachai\DbSnapshotSyncLaravel\Transfer\ExportStream;
use Phattarachai\DbSnapshotSyncLaravel\Transfer\SnapshotStreamer;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Runs on the source box, started over ssh by `snapshot:sync`: writes one
 * snapshot to stdout and everything else (progress, the closing size + sha256
 * line) to stderr. Whoever can ssh in and run artisan as the app's user can
 * pull, which is exactly who can already read the box's .env and database.
 */
class ExportCommand extends Command
{
    protected $signature = 'snapshot:export
        {--fresh : Build a new snapshot of the default connection first, stream it, then delete it}
        {--disk= : Disk to stream from (default: db-snapshot-sync.disk)}
        {--pattern= : Only file names matching this glob (default: .sql / .sql.gz / .sql.zst snapshots)}';

    protected $description = 'Write the newest DB snapshot (or a fresh one) to stdout, for snapshot:sync over ssh.';

    public function handle(ExportStream $stream, SnapshotStreamer $streamer, SyncSnapshotBuilder $builder): int
    {
        if (class_exists(Pulse::class)) {
            Pulse::stopRecording();
        }

        if ($stream->isTerminal()) {
            $this->note('<error>snapshot:export writes a binary snapshot to stdout. Redirect it to a file; snapshot:sync runs it over ssh for you.</error>');

            return self::FAILURE;
        }

        $diskName = (string) ($this->option('disk') ?: config('db-snapshot-sync.disk'));
        $pattern = $this->option('pattern') ?: null;

        if ($this->option('fresh') && ($diskName !== (string) config('db-snapshot-sync.disk') || $pattern !== null)) {
            $this->note('<error>--fresh builds a snapshot of the default connection on the snapshots disk; it cannot be combined with --disk or --pattern.</error>');

            return self::FAILURE;
        }

        $disk = Storage::disk($diskName);
        $fresh = null;

        try {
            if ($this->option('fresh')) {
                $this->note('Building a fresh snapshot…');
                $fresh = $builder->build();
            }

            $file = $fresh ?? SnapshotCandidates::newest($disk, is_string($pattern) ? $pattern : null);

            if ($file === null) {
                $this->note("<error>No snapshot on disk [{$diskName}].</error> Schedule snapshot:create there, or pull with --fresh.");

                return self::FAILURE;
            }

            $this->note("Streaming {$file} (".number_format($disk->size($file)).' bytes)…');

            $out = $stream->open();
            $result = $streamer->stream($disk, $file, $out);

            $this->note($result->toLine());
        } catch (Throwable $e) {
            $this->note('<error>'.$e->getMessage().'</error>');

            return self::FAILURE;
        } finally {
            if ($fresh !== null) {
                $disk->delete($fresh);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Stdout carries the snapshot, so every message goes to stderr.
     */
    private function note(string $message): void
    {
        $this->output->getErrorStyle()->writeln($message, OutputInterface::OUTPUT_NORMAL);
    }
}
