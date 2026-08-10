<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Sanitizers;

use Illuminate\Contracts\Filesystem\Filesystem;

interface Sanitizer
{
    /**
     * Strip lines that reject on a locally-installed DB client from the
     * snapshot at $file, returning the filename snapshot:load should consume —
     * the same file when the driver mutates in place, a new one when it writes
     * a separate sanitized copy.
     */
    public function sanitize(Filesystem $disk, string $file): string;
}
