<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Support;

use RuntimeException;

/**
 * The one way both `snapshot:restore` and `snapshot:sync` load a dump: streamed
 * through the engine's own client (psql or mysql), never read whole into PHP.
 */
class SnapshotRestorer
{
    /**
     * @throws RuntimeException when the load fails or the driver has no loader
     */
    public function restore(string $path, string $connection, bool $dropTables = true, ?string $role = null): void
    {
        $driver = (string) config("database.connections.{$connection}.driver");

        match ($driver) {
            'pgsql' => app(PsqlLoader::class)->load($path, $connection, $dropTables, $role),
            'mysql', 'mariadb' => app(MysqlLoader::class)->load($path, $connection, $dropTables),
            default => throw new RuntimeException("No loader for connection [{$connection}] (driver [{$driver}]); pgsql, mysql and mariadb are supported."),
        };
    }
}
