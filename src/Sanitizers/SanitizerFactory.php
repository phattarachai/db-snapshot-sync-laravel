<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Sanitizers;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

final readonly class SanitizerFactory
{
    public function __construct(private Repository $config) {}

    public function forDefaultConnection(): Sanitizer
    {
        $connection = (string) $this->config->get('database.default');

        return $this->for((string) $this->config->get("database.connections.{$connection}.driver"));
    }

    public function for(string $driver): Sanitizer
    {
        return match ($driver) {
            'pgsql' => new PostgresSanitizer(
                $this->config->get('db-snapshot-sync.sanitizer.pgsql.drop_prefixes', []),
                $this->config->get('db-snapshot-sync.sanitizer.pgsql.drop_contains', []),
                $this->config->get('db-snapshot-sync.sanitizer.pgsql.drop_patterns', []),
            ),
            'mysql', 'mariadb' => new MySqlSanitizer(
                $this->config->get('db-snapshot-sync.sanitizer.mysql.sed', []),
            ),
            default => throw new InvalidArgumentException("Unsupported database driver [{$driver}] for snapshot sanitizing. Supported: pgsql, mysql."),
        };
    }
}
