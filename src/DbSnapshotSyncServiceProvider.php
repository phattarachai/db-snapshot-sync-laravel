<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Override;
use Phattarachai\DbSnapshotSyncLaravel\Console\BackupCheckCommand;
use Phattarachai\DbSnapshotSyncLaravel\Console\BackupCommand;
use Phattarachai\DbSnapshotSyncLaravel\Console\DrillCommand;
use Phattarachai\DbSnapshotSyncLaravel\Console\InstallCommand;
use Phattarachai\DbSnapshotSyncLaravel\Console\PruneCommand;
use Phattarachai\DbSnapshotSyncLaravel\Console\SedCommand;
use Phattarachai\DbSnapshotSyncLaravel\Console\SyncCommand;
use Phattarachai\DbSnapshotSyncLaravel\Snapshots\SnapshotFactory;
use Phattarachai\DbSnapshotSyncLaravel\Snapshots\SnapshotRepository;
use Phattarachai\DbSnapshotSyncLaravel\Support\PsqlLoader;
use Spatie\DbSnapshots\SnapshotFactory as SpatieSnapshotFactory;
use Spatie\DbSnapshots\SnapshotRepository as SpatieSnapshotRepository;

class DbSnapshotSyncServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/db-snapshot-sync.php', 'db-snapshot-sync');

        $this->app->bind(PsqlLoader::class, fn (): PsqlLoader => new PsqlLoader(
            (string) config('db-snapshot-sync.psql', 'psql'),
        ));

        $this->app->bind(SpatieSnapshotFactory::class, SnapshotFactory::class);

        // An extender, not a binding: spatie binds its repository while it boots,
        // whichever provider boots first, and an extender outlives a later bind().
        // snapshot:load, :list, :delete and :cleanup then see .sql.zst too.
        $this->app->extend(SpatieSnapshotRepository::class, fn (SpatieSnapshotRepository $repository): SnapshotRepository => $repository instanceof SnapshotRepository
            ? $repository
            : new SnapshotRepository(Storage::disk((string) config('db-snapshots.disk'))));
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
                BackupCheckCommand::class,
                BackupCommand::class,
                DrillCommand::class,
                InstallCommand::class,
                PruneCommand::class,
                SedCommand::class,
                SyncCommand::class,
            ]);
        }
    }
}
