<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Events;

use Illuminate\Support\Carbon;

/**
 * Both outcomes of `snapshot:backup-check`. Laravel resolves listeners by the
 * event's own class and the interfaces it implements, never its parent class,
 * so listen on this interface to receive SnapshotBackupStale and
 * SnapshotBackupHealthy alike.
 */
interface SnapshotBackupChecked
{
    public string $disk { get; }

    public ?string $newest { get; }

    public ?Carbon $newestAt { get; }

    public ?int $ageSeconds { get; }

    public int $staleAfterHours { get; }

    public ?string $error { get; }

    public function ageHours(): ?float;

    public function describe(): string;
}
