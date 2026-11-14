<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Transfer;

/**
 * What `snapshot:export` sent: the file's name, byte count and sha256. The box
 * prints it as the last stderr line, so the puller can prove the bytes that
 * reached it are the whole file before anything is sanitized or loaded.
 */
final readonly class ExportResult
{
    public const string MARKER = '[snapshot-export] ';

    public function __construct(
        public string $name,
        public int $size,
        public string $sha256,
    ) {}

    public function toLine(): string
    {
        return self::MARKER.json_encode(['name' => $this->name, 'size' => $this->size, 'sha256' => $this->sha256], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public static function fromLine(string $line): ?self
    {
        if (! str_starts_with($line, self::MARKER)) {
            return null;
        }

        $data = json_decode(substr(rtrim($line), strlen(self::MARKER)), true);

        if (! is_array($data) || ! is_string($data['name'] ?? null) || ! is_int($data['size'] ?? null) || ! is_string($data['sha256'] ?? null)) {
            return null;
        }

        return new self($data['name'], $data['size'], $data['sha256']);
    }
}
