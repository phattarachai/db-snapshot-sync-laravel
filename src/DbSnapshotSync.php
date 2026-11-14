<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel;

use Phattarachai\DbSnapshotSyncLaravel\Console\RestoreCommand;
use Phattarachai\DbSnapshotSyncLaravel\Console\SyncCommand;

final class DbSnapshotSync
{
    /**
     * The commands that replace a database: snapshot:restore and snapshot:sync.
     *
     * @var list<class-string<RestoreCommand|SyncCommand>>
     */
    public const array DESTRUCTIVE_COMMANDS = [RestoreCommand::class, SyncCommand::class];

    /**
     * Prohibit (or allow again) every command of this package that replaces a
     * database, the way DB::prohibitDestructiveCommands() does for migrate:fresh
     * and db:wipe. The package's own environment guards still apply on top.
     */
    public static function prohibitDestructiveCommands(bool $prohibit = true): void
    {
        foreach (self::DESTRUCTIVE_COMMANDS as $command) {
            $command::prohibit($prohibit);
        }
    }
}
