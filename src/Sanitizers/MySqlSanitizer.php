<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Sanitizers;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Phattarachai\DbSnapshotSyncLaravel\Compression\Compression;
use Phattarachai\DbSnapshotSyncLaravel\Compression\Zstd;
use RuntimeException;

final class MySqlSanitizer implements Sanitizer
{
    /**
     * @param  list<string>  $sedExpressions
     */
    public function __construct(private array $sedExpressions = []) {}

    /**
     * Writes {stem}.sanitized.sql.zst for a zstd snapshot, else {stem}.sanitized.sql.gz.
     */
    public function sanitize(Filesystem $disk, string $file): string
    {
        $inputPath = $disk->path($file);
        $input = Compression::sniff($inputPath);
        $output = $input === Compression::Zstd ? Compression::Zstd : Compression::Gzip;
        $outputName = Compression::stem($file).'.sanitized'.$output->suffix();
        $outputPath = $disk->path($outputName);

        $stages = [
            match ($input) {
                Compression::None => 'cat',
                Compression::Gzip => 'gunzip -c',
                Compression::Zstd => $this->shell(Zstd::fromConfig()->decompressCommand()),
            }.' '.escapeshellarg($inputPath),
        ];

        if ($this->sedExpressions !== []) {
            $stages[] = 'sed -E '.implode(' ', array_map(
                fn (string $expression): string => '-e '.escapeshellarg($expression),
                $this->sedExpressions,
            ));
        }

        $stages[] = ($output === Compression::Zstd ? $this->shell(Zstd::fromConfig()->compressCommand()) : 'gzip').' > '.escapeshellarg($outputPath);

        // pipefail: a corrupt or truncated input fails the run instead of
        // leaving a short sanitized copy behind.
        $result = Process::run(['bash', '-c', 'set -o pipefail; '.implode(' | ', $stages)]);

        if ($result->failed()) {
            throw new RuntimeException("sed pipeline failed: {$result->errorOutput()}");
        }

        return $outputName;
    }

    /**
     * @param  list<string>  $command
     */
    private function shell(array $command): string
    {
        return implode(' ', array_map(escapeshellarg(...), $command));
    }
}
