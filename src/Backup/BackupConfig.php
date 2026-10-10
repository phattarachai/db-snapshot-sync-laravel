<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Backup;

/**
 * The `db-snapshot-sync.backup` block, read once and typed.
 */
final readonly class BackupConfig
{
    /**
     * @param  list<string>  $disks
     * @param  list<string>  $protect
     * @param  list<string>  $reject
     */
    public function __construct(
        public string $localDisk,
        public array $disks,
        public string $path,
        public int $localDays,
        public int $keepMin,
        public int $dailyDays,
        public int $weeklyWeeks,
        public int $staleAfterHours,
        public array $protect,
        public array $reject,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            localDisk: (string) config('db-snapshot-sync.disk', 'snapshots'),
            disks: self::strings(config('db-snapshot-sync.backup.disks', [])),
            path: trim((string) config('db-snapshot-sync.backup.path', 'db'), '/'),
            localDays: max(1, (int) config('db-snapshot-sync.backup.local_days', 14)),
            keepMin: max(0, (int) config('db-snapshot-sync.backup.keep_min', 3)),
            dailyDays: max(1, (int) config('db-snapshot-sync.backup.daily_days', 14)),
            weeklyWeeks: max(0, (int) config('db-snapshot-sync.backup.weekly_weeks', 8)),
            staleAfterHours: max(1, (int) config('db-snapshot-sync.backup.stale_after_hours', 26)),
            protect: self::strings(config('db-snapshot-sync.backup.protect', [])),
            reject: self::strings(config('db-snapshot-sync.api.reject', [])),
        );
    }

    /**
     * A protected snapshot (e.g. a committed schema baseline) is never pruned
     * and never leaves the box. Matched on the exact snapshot name.
     */
    public function protects(SnapshotFile $file): bool
    {
        return in_array($file->stem(), $this->protect, true);
    }

    /**
     * Anything the internal API refuses to serve (a `.sanitized.` intermediate,
     * a schema-only baseline) is not a restore point. Matched by substring.
     */
    public function rejects(SnapshotFile $file): bool
    {
        return array_any($this->reject, fn (string $fragment): bool => str_contains($file->name(), $fragment));
    }

    public function dailyDir(): string
    {
        return $this->dir('daily');
    }

    public function weeklyDir(): string
    {
        return $this->dir('weekly');
    }

    private function dir(string $name): string
    {
        return $this->path === '' ? $name : "{$this->path}/{$name}";
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return array_values(array_filter(
            array_map(strval(...), array_filter((array) $value, is_scalar(...))),
            fn (string $item): bool => $item !== '',
        ));
    }
}
