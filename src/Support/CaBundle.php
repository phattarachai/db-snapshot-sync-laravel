<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Support;

use RuntimeException;

/**
 * Resolves the Guzzle `verify` value for the sync download.
 *
 * When a source edge serves an incomplete certificate chain (it presents the
 * leaf but omits an intermediate), PHP's cURL cannot build a trust path and
 * fails with "unable to get local issuer certificate" (error 60). Appending the
 * missing intermediate to the system trust store lets the chain verify while
 * keeping full verification on. When no extra CA is configured, ordinary
 * verification (`true`) is used.
 */
final class CaBundle
{
    /**
     * @return true|string `true` for default verification, or the path to a
     *                     merged bundle (system trust store + the extra CA).
     */
    public static function resolve(?string $extra, ?string $basePath = null, ?string $mergedPath = null): bool|string
    {
        if ($extra === null || $extra === '') {
            return true;
        }

        $extraPath = self::absolute($extra, $basePath);

        if (! is_file($extraPath)) {
            throw new RuntimeException("DB_SNAPSHOT_SYNC_CA_BUNDLE is set but the file was not found: {$extraPath}");
        }

        $systemBundle = openssl_get_cert_locations()['default_cert_file'] ?? null;

        if (! is_string($systemBundle) || ! is_file($systemBundle)) {
            return $extraPath;
        }

        $mergedPath ??= storage_path('app/db-snapshot-sync-ca-bundle.pem');

        file_put_contents(
            $mergedPath,
            file_get_contents($systemBundle)."\n".file_get_contents($extraPath),
        );

        return $mergedPath;
    }

    private static function absolute(string $path, ?string $basePath): string
    {
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }

        $basePath ??= function_exists('base_path') ? base_path() : getcwd();

        return rtrim((string) $basePath, '/').'/'.$path;
    }
}
