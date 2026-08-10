<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Sanitizers;

use Illuminate\Contracts\Filesystem\Filesystem;

final class PostgresSanitizer implements Sanitizer
{
    /**
     * @param  list<string>  $dropPrefixes
     * @param  list<string>  $dropContains
     * @param  list<string>  $dropPatterns
     */
    public function __construct(
        private array $dropPrefixes = [],
        private array $dropContains = [],
        private array $dropPatterns = [],
    ) {}

    public function sanitize(Filesystem $disk, string $file): string
    {
        $isGz = str_ends_with($file, '.gz');
        $raw = (string) $disk->get($file);
        $content = $isGz ? (string) gzdecode($raw) : $raw;

        $content = $this->applyPatterns($this->filterLines($content));

        $disk->put($file, $isGz ? (string) gzencode($content, 9) : $content);

        return $file;
    }

    private function filterLines(string $content): string
    {
        if ($this->dropPrefixes === [] && $this->dropContains === []) {
            return $content;
        }

        $kept = array_filter(
            explode("\n", $content),
            fn (string $line): bool => ! $this->matchesPrefix($line) && ! $this->matchesContains($line),
        );

        return implode("\n", $kept);
    }

    private function matchesPrefix(string $line): bool
    {
        return array_any($this->dropPrefixes, fn (string $prefix): bool => str_starts_with($line, $prefix));
    }

    private function matchesContains(string $line): bool
    {
        return array_any($this->dropContains, fn (string $needle): bool => str_contains($line, $needle));
    }

    private function applyPatterns(string $content): string
    {
        foreach ($this->dropPatterns as $pattern) {
            $content = (string) preg_replace($pattern, '', $content);
        }

        return $content;
    }
}
