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
        return $this->forConnection((string) $this->config->get('database.default'));
    }

    public function forConnection(string $connection): Sanitizer
    {
        return $this->for($this->driverOf($connection));
    }

    public function driverOf(string $connection): string
    {
        $driver = $this->config->get("database.connections.{$connection}.driver");

        if (! is_string($driver) || $driver === '') {
            throw new InvalidArgumentException("Database connection [{$connection}] is not configured.");
        }

        return $driver;
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
