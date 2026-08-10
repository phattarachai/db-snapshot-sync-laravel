<?php

declare(strict_types=1);

it('refuses to run outside the allowed environments', function (): void {
    // Default testbench environment is "testing", which is not allowed by default.
    $this->artisan('snapshot:sync')
        ->assertFailed()
        ->expectsOutputToContain('snapshot:sync may only run in');
});

it('errors on an unknown source', function (): void {
    config(['db-snapshot-sync.sync.allowed_environments' => ['testing']]);

    $this->artisan('snapshot:sync', ['--source' => 'bogus'])
        ->assertFailed()
        ->expectsOutputToContain('Unknown or unconfigured');
});

it('errors when the token is missing', function (): void {
    config([
        'db-snapshot-sync.sync.allowed_environments' => ['testing'],
        'db-snapshot-sync.token' => null,
    ]);

    $this->artisan('snapshot:sync')
        ->assertFailed()
        ->expectsOutputToContain('INTERNAL_API_TOKEN is not set');
});
