<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Backup;

/**
 * What `snapshot:drill` found when it restored the newest off-site copy.
 *
 * Row counts are compared with the live database as it is *now*, after the
 * snapshot was taken, so small deltas are expected. The drill fails on what a
 * broken backup looks like instead: a live table missing from the restore, or
 * one that has rows live but restored empty (outside `dump.exclude_table_data`).
 */
final class DrillResult
{
    public ?string $snapshot = null;

    public ?string $error = null;

    /** @var list<array{table: string, live: ?int, restored: ?int}> */
    public array $tables = [];

    /** @var list<string> live tables the restore does not have */
    public array $missing = [];

    /** @var list<string> live tables with rows that restored empty */
    public array $emptied = [];

    public float $downloadSeconds = 0.0;

    public float $loadSeconds = 0.0;

    public float $totalSeconds = 0.0;

    public function __construct(
        public readonly string $disk,
        public readonly string $connection,
        public readonly string $scratchDatabase,
    ) {}

    public function passed(): bool
    {
        return $this->error === null && $this->snapshot !== null && $this->missing === [] && $this->emptied === [];
    }

    /**
     * @return list<array{table: string, live: ?int, restored: ?int}>
     */
    public function differences(): array
    {
        return array_values(array_filter($this->tables, fn (array $row): bool => $row['live'] !== $row['restored']));
    }
}
