<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Sanitizers;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final class MySqlSanitizer implements Sanitizer
{
    /**
     * @param  list<string>  $sedExpressions
     */
    public function __construct(private array $sedExpressions = []) {}

    public function sanitize(Filesystem $disk, string $file): string
    {
        $inputPath = $disk->path($file);
        $outputName = $this->sanitizedNameFor($file);
        $outputPath = $disk->path($outputName);

        $stages = [
            (str_ends_with($inputPath, '.gz') ? 'gunzip -c ' : 'cat ').escapeshellarg($inputPath),
        ];

        if ($this->sedExpressions !== []) {
            $stages[] = 'sed -E '.implode(' ', array_map(
                fn (string $expression): string => '-e '.escapeshellarg($expression),
                $this->sedExpressions,
            ));
        }

        $stages[] = 'gzip > '.escapeshellarg($outputPath);

        $result = Process::run(['bash', '-c', implode(' | ', $stages)]);

        if ($result->failed()) {
            throw new RuntimeException("sed pipeline failed: {$result->errorOutput()}");
        }

        return $outputName;
    }

    private function sanitizedNameFor(string $file): string
    {
        $stem = preg_replace('/\.sql(\.gz)?$/', '', $file);

        return "{$stem}.sanitized.sql.gz";
    }
}
