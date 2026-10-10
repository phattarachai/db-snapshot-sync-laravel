<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Events;

/**
 * A target's newest daily copy is older than `stale_after_hours`, the target
 * holds none, or it could not be read (see $error).
 */
final class SnapshotBackupStale extends SnapshotBackupStatus {}
