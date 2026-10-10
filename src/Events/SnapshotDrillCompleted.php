<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Phattarachai\DbSnapshotSyncLaravel\Backup\DrillResult;

/**
 * A restore drill finished, passed or not — check $result->passed().
 */
final class SnapshotDrillCompleted
{
    use Dispatchable;

    public function __construct(public readonly DrillResult $result) {}
}
