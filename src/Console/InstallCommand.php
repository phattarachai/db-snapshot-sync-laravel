<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Console;

use Illuminate\Console\Command;
use Phattarachai\DbSnapshotSyncLaravel\Support\EnvWriter;
use Symfony\Component\Console\Output\OutputInterface;

class InstallCommand extends Command
{
    protected $signature = 'db-snapshot-sync:install
        {--dry-run : Print intended changes without writing files}';

    protected $description = 'Install DB Snapshot Sync: publish config, write env keys, and print the source-side snippets.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->publishConfig($dryRun);
        $this->writeEnvKeys($dryRun);
        $this->emitSnippets();
        $this->summary($dryRun);

        return self::SUCCESS;
    }

    private function publishConfig(bool $dryRun): void
    {
        if ($dryRun) {
            $this->line('Would publish: config/db-snapshot-sync.php (tag: db-snapshot-sync-config).');

            return;
        }

        $this->call('vendor:publish', ['--tag' => 'db-snapshot-sync-config', '--force' => false]);
    }

    private function writeEnvKeys(bool $dryRun): void
    {
        $defaults = [
            'INTERNAL_API_TOKEN' => bin2hex(random_bytes(24)),
            'DB_SNAPSHOT_SYNC_PROD_URL' => '',
            'DB_SNAPSHOT_SYNC_UAT_URL' => '',
            'DB_SNAPSHOT_SYNC_API' => 'false',
            'DB_SNAPSHOT_SYNC_CA_BUNDLE' => '',
        ];

        if ($dryRun) {
            $this->line('Would set in .env (if absent): '.implode(', ', array_keys($defaults)));

            return;
        }

        $env = new EnvWriter(base_path('.env'));
        $written = [];

        foreach ($defaults as $key => $value) {
            if ($env->setIfAbsent($key, $value)) {
                $written[] = $key;
            }
        }

        $example = new EnvWriter(base_path('.env.example'));

        if ($example->exists()) {
            foreach (array_keys($defaults) as $key) {
                $example->setIfAbsent($key, '');
            }
        }

        $this->info($written === []
            ? 'Snapshot-sync env keys already present in .env — left as-is.'
            : 'Wrote '.count($written).' env key(s): '.implode(', ', $written));
    }

    private function emitSnippets(): void
    {
        $driver = (string) config('database.connections.'.config('database.default').'.driver');

        $this->writeRawHeader('1. Point config/db-snapshots.php at the "snapshots" disk, and make compression env-driven:');
        $this->writeRawSnippet(<<<'PHP'
            'disk' => 'snapshots',
            'compress' => env('DB_SNAPSHOTS_COMPRESS', true),
            PHP);

        $this->writeRawHeader('2. Add the "snapshots" disk to config/filesystems.php (if absent):');
        $this->writeRawSnippet(<<<'PHP'
            'snapshots' => [
                'driver' => 'local',
                'root' => storage_path('app/snapshots'),
                'serve' => true,
                'throw' => false,
                'report' => false,
            ],
            PHP);

        $this->writeRawHeader('3. Exclude transient framework data from the dump — config/database.php, inside the connection "dump" key:');
        $this->writeRawSnippet($this->dumpExclusionSnippet($driver));

        $this->writeRawHeader('4. Schedule the nightly snapshot the default sync downloads — bootstrap/app.php ->withSchedule():');
        $this->writeRawSnippet(<<<'PHP'
            $schedule->command('snapshot:create')->dailyAt('04:00');
            $schedule->command('snapshot:cleanup --keep=36')->dailyAt('04:05');
            PHP);

        $this->writeRawHeader('5. Ignore local snapshots — .gitignore:');
        $this->writeRawSnippet('storage/app/snapshots/*.sql.gz');

        $this->writeRawHeader('6. On the SOURCE server (production/UAT): set the same INTERNAL_API_TOKEN, set DB_SNAPSHOT_SYNC_API=true, and set this project\'s URL as DB_SNAPSHOT_SYNC_PROD_URL locally.');
    }

    private function dumpExclusionSnippet(string $driver): string
    {
        $tables = ['pulse_aggregates', 'pulse_entries', 'pulse_values', 'cache', 'cache_locks', 'failed_jobs', 'jobs', 'job_batches', 'sessions'];

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $ignores = array_map(fn (string $t): string => "            '--ignore-table=\${DB_DATABASE}.{$t}',", $tables);

            return "'dump' => [\n    'addExtraOption' => implode(' ', [\n        '--column-statistics=0',\n".implode("\n", $ignores)."\n    ]),\n],";
        }

        $excludes = array_map(fn (string $t): string => "            '--exclude-table-data={$t}',", $tables);

        return "'dump' => [\n    'addExtraOption' => implode(' ', [\n        '--no-owner',\n        '--no-privileges',\n".implode("\n", $excludes)."\n    ]),\n],";
    }

    private function summary(bool $dryRun): void
    {
        $this->writeRawLine('');

        if ($dryRun) {
            $this->info('--dry-run: no files were modified.');

            return;
        }

        $this->info('DB Snapshot Sync installed. Fill the source URLs in .env, then: php artisan snapshot:sync --no-load (dry run).');
    }

    private function writeRawHeader(string $message): void
    {
        $this->output->writeln('', OutputInterface::OUTPUT_RAW);
        $this->output->writeln($message, OutputInterface::OUTPUT_RAW);
    }

    private function writeRawSnippet(string $snippet): void
    {
        $this->output->writeln('', OutputInterface::OUTPUT_RAW);

        foreach (explode("\n", $snippet) as $line) {
            $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        $this->output->writeln('', OutputInterface::OUTPUT_RAW);
    }

    private function writeRawLine(string $line): void
    {
        $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
    }
}
