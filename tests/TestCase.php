<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Phattarachai\DbSnapshotSyncLaravel\DbSnapshotSyncServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [DbSnapshotSyncServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql.driver', 'pgsql');

        $app['config']->set('db-snapshot-sync.disk', 'snapshots');
        $app['config']->set('db-snapshot-sync.token', 'secret-token');
        $app['config']->set('db-snapshot-sync.api.enabled', true);
        $app['config']->set('db-snapshot-sync.sources.production', 'https://source.test');
    }
}
