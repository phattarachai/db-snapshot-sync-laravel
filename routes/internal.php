<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Phattarachai\DbSnapshotSyncLaravel\Http\Controllers\SnapshotController;
use Phattarachai\DbSnapshotSyncLaravel\Http\Middleware\EnsureInternalToken;

Route::prefix((string) config('db-snapshot-sync.api.prefix', 'internal'))
    ->middleware(array_merge([EnsureInternalToken::class], (array) config('db-snapshot-sync.api.middleware', [])))
    ->group(function (): void {
        Route::get('snapshots', [SnapshotController::class, 'index'])->name('db-snapshot-sync.snapshots.index');
        Route::post('snapshots', [SnapshotController::class, 'store'])->name('db-snapshot-sync.snapshots.store');
        Route::get('snapshots/latest', [SnapshotController::class, 'latest'])->name('db-snapshot-sync.snapshots.latest');
    });
