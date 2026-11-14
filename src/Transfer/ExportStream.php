<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Transfer;

/**
 * Where `snapshot:export` writes the snapshot's bytes: the process's stdout,
 * which ssh carries back to the machine running `snapshot:sync`.
 */
class ExportStream
{
    /**
     * @return resource
     */
    public function open()
    {
        return fopen('php://stdout', 'wb') ?: STDOUT;
    }

    /**
     * True when stdout is a terminal, where a binary dump would only flood it.
     */
    public function isTerminal(): bool
    {
        return stream_isatty(STDOUT);
    }
}
