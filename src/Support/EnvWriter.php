<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Support;

final readonly class EnvWriter
{
    public function __construct(private string $path) {}

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function has(string $key): bool
    {
        if (! $this->exists()) {
            return false;
        }

        $contents = (string) file_get_contents($this->path);
        $pattern = '/^(\s*)'.preg_quote($key, '/').'\s*=.*$/m';

        return (bool) preg_match($pattern, $contents);
    }

    public function set(string $key, string $value, bool $raw = false): void
    {
        $contents = $this->exists() ? (string) file_get_contents($this->path) : '';
        $quoted = $this->quote($value, $raw);
        $pattern = '/^(\s*)'.preg_quote($key, '/').'\s*=.*$/m';

        if (preg_match($pattern, $contents)) {
            $contents = preg_replace($pattern, '${1}'.$key.'='.$this->escapeReplacement($quoted), $contents) ?? $contents;
        } else {
            if ($contents !== '' && ! str_ends_with($contents, "\n")) {
                $contents .= "\n";
            }

            $contents .= $key.'='.$quoted."\n";
        }

        file_put_contents($this->path, $contents);
    }

    public function setIfAbsent(string $key, string $value, bool $raw = false): bool
    {
        if ($this->has($key)) {
            return false;
        }

        $this->set($key, $value, $raw);

        return true;
    }

    private function quote(string $value, bool $raw = false): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/[\s#"\'\\\\$]/', $value)) {
            $replacements = $raw
                ? ['\\' => '\\\\', '"' => '\"']
                : ['\\' => '\\\\', '"' => '\"', '$' => '\\$'];

            return '"'.strtr($value, $replacements).'"';
        }

        return $value;
    }

    private function escapeReplacement(string $value): string
    {
        return str_replace(['\\', '$'], ['\\\\', '\\$'], $value);
    }
}
