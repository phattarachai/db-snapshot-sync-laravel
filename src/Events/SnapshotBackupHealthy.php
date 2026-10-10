<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Events;

/**
 * A target's newest daily copy is within `stale_after_hours`.
 */
final class SnapshotBackupHealthy extends SnapshotBackupStatus {}
