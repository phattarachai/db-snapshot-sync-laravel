<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel;

use Illuminate\Support\ServiceProvider;
use Override;
use Phattarachai\DbSnapshotSyncLaravel\Console\InstallCommand;
use Phattarachai\DbSnapshotSyncLaravel\Console\SedCommand;
use Phattarachai\DbSnapshotSyncLaravel\Console\SyncCommand;
use Phattarachai\DbSnapshotSyncLaravel\Support\PsqlLoader;

class DbSnapshotSyncServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/db-snapshot-sync.php', 'db-snapshot-sync');

        $this->app->bind(PsqlLoader::class, fn (): PsqlLoader => new PsqlLoader(
            (string) config('db-snapshot-sync.psql', 'psql'),
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/db-snapshot-sync.php' => config_path('db-snapshot-sync.php'),
        ], 'db-snapshot-sync-config');

        if (config('db-snapshot-sync.api.enabled') === true) {
            $this->loadRoutesFrom(__DIR__.'/../routes/internal.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                SedCommand::class,
                SyncCommand::class,
            ]);
        }
    }
}
