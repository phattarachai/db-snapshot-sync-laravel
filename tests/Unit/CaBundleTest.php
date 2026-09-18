<?php

declare(strict_types=1);

use Phattarachai\DbSnapshotSyncLaravel\Support\CaBundle;

it('uses default verification when no extra CA is configured', function (): void {
    expect(CaBundle::resolve(null))->toBeTrue();
    expect(CaBundle::resolve(''))->toBeTrue();
});

it('appends the extra CA to the system trust store', function (): void {
    $extra = tempnam(sys_get_temp_dir(), 'ca_').'.pem';
    file_put_contents($extra, "-----BEGIN CERTIFICATE-----\nEXTRA-INTERMEDIATE\n-----END CERTIFICATE-----\n");
    $merged = tempnam(sys_get_temp_dir(), 'merged_').'.pem';

    $result = CaBundle::resolve($extra, mergedPath: $merged);

    expect($result)->toBeString();
    expect(file_get_contents((string) $result))->toContain('EXTRA-INTERMEDIATE');

    @unlink($extra);
    @unlink($merged);
});

it('resolves a relative CA path against the base path', function (): void {
    $dir = sys_get_temp_dir().'/ca_'.uniqid();
    mkdir($dir.'/certs', 0o755, true);
    file_put_contents($dir.'/certs/inter.pem', "-----BEGIN CERTIFICATE-----\nREL\n-----END CERTIFICATE-----\n");
    $merged = tempnam(sys_get_temp_dir(), 'merged_').'.pem';

    $result = CaBundle::resolve('certs/inter.pem', basePath: $dir, mergedPath: $merged);

    expect(file_get_contents((string) $result))->toContain('REL');

    @unlink($merged);
});

it('throws when the configured CA file is missing', function (): void {
    CaBundle::resolve('/nonexistent/path/to/ca.pem');
})->throws(RuntimeException::class, 'DB_SNAPSHOT_SYNC_CA_BUNDLE');
