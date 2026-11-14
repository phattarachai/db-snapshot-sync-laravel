<?php

declare(strict_types=1);

use Phattarachai\DbSnapshotSyncLaravel\Transfer\ExportResult;
use Phattarachai\DbSnapshotSyncLaravel\Transfer\SshSource;

it('runs artisan in the app directory as the ssh user', function (): void {
    $source = SshSource::fromConfig('production', ['ssh' => 'prod-box', 'path' => '/var/www/shop/current']);

    expect($source->remoteCommand(['snapshot:export']))
        ->toBe("cd '/var/www/shop/current' && 'php' '-d' 'display_errors=stderr' 'artisan' 'snapshot:export' '--no-ansi' '--no-interaction'");
});

it('runs artisan through sudo -n as run_as, with its own php binary', function (): void {
    $source = SshSource::fromConfig('production', ['ssh' => 'prod-box', 'path' => '/var/www/shop', 'run_as' => 'www-data', 'php' => 'php8.5']);

    expect($source->remoteCommand(['snapshot:export', '--fresh']))
        ->toBe("cd '/var/www/shop' && 'sudo' '-n' '-u' 'www-data' 'env' 'HOME=/tmp' 'php8.5' '-d' 'display_errors=stderr' 'artisan' 'snapshot:export' '--fresh' '--no-ansi' '--no-interaction'");
});

it('quotes a path that tries to break out of the remote shell', function (): void {
    $source = SshSource::fromConfig('production', ['ssh' => 'prod-box', 'path' => "/srv/x'; rm -rf / #"]);

    expect($source->remoteCommand(['snapshot:export']))->toStartWith("cd '/srv/x'\\''; rm -rf / #' && ");
});

it('passes the configured ssh options, the source\'s own last', function (): void {
    config(['db-snapshot-sync.ssh.options' => ['BatchMode' => 'yes', 'ConnectTimeout' => 15]]);

    $source = SshSource::fromConfig('production', [
        'ssh' => 'prod-box',
        'path' => '/var/www/shop',
        'ssh_options' => ['ConnectTimeout' => 40, 'ProxyJump' => 'bastion'],
    ]);

    $command = $source->command('ssh', ['snapshot:export']);

    expect(array_slice($command, 0, 8))->toBe(['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=40', '-o', 'ProxyJump=bastion', 'prod-box']);
    expect(end($command))->toBe($source->remoteCommand(['snapshot:export']));
});

it('calls a sudoers wrapper with the export\'s options instead of cd + artisan', function (): void {
    $source = SshSource::fromConfig('production', ['ssh' => 'prod-box', 'run_as' => 'www-data', 'wrapper' => '/usr/local/bin/snapshot-export']);

    expect($source->remoteCommand(['snapshot:export', '--fresh']))
        ->toBe("'sudo' '-n' '-u' 'www-data' '/usr/local/bin/snapshot-export' '--fresh' '--no-ansi' '--no-interaction'");
});

it('refuses an ssh source without a path', function (): void {
    SshSource::fromConfig('production', ['ssh' => 'prod-box', 'path' => '']);
})->throws(InvalidArgumentException::class, 'sets ssh [prod-box] but no path');

it('round-trips the export result line and ignores anything else', function (): void {
    $result = new ExportResult('2026-01-01.sql.gz', 42, str_repeat('a', 64));

    expect(ExportResult::fromLine($result->toLine()))->toEqual($result);
    expect(ExportResult::fromLine('Streaming 2026-01-01.sql.gz…'))->toBeNull();
    expect(ExportResult::fromLine('[snapshot-export] {"name":"x"}'))->toBeNull();
});
