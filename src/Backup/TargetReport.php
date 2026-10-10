<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Backup;

/**
 * What one `snapshot:backup` run did to one target disk.
 */
final class TargetReport
{
    /** @var list<string> */
    public array $uploaded = [];

    /** @var list<string> */
    public array $weekly = [];

    /** @var list<string> */
    public array $pruned = [];

    public ?string $error = null;

    public function __construct(public readonly string $disk) {}

    public function failed(): bool
    {
        return $this->error !== null;
    }
}
