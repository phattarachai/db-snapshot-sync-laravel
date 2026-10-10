<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * The state of one target disk's newest daily copy, as `snapshot:backup-check`
 * found it. Listen for SnapshotBackupStale / SnapshotBackupHealthy (or this base
 * class for both) and map it to your own alerting.
 */
abstract class SnapshotBackupStatus
{
    use Dispatchable;

    /**
     * @param  string  $disk  the target disk name
     * @param  ?string  $newest  path of the newest daily copy, null when there is none
     * @param  ?Carbon  $newestAt  when that copy landed on the target
     * @param  ?int  $ageSeconds  how old it is now, null when there is none
     * @param  int  $staleAfterHours  the threshold it was judged against
     * @param  ?string  $error  why the target could not be read, if it could not
     */
    public function __construct(
        public readonly string $disk,
        public readonly ?string $newest,
        public readonly ?Carbon $newestAt,
        public readonly ?int $ageSeconds,
        public readonly int $staleAfterHours,
        public readonly ?string $error = null,
    ) {}

    public function ageHours(): ?float
    {
        return $this->ageSeconds === null ? null : round($this->ageSeconds / 3600, 2);
    }

    public function describe(): string
    {
        return match (true) {
            $this->error !== null => "[{$this->disk}] unreadable: {$this->error}",
            $this->newest === null => "[{$this->disk}] holds no daily copy",
            default => "[{$this->disk}] newest {$this->newest}, {$this->ageHours()}h old (stale after {$this->staleAfterHours}h)",
        };
    }
}
