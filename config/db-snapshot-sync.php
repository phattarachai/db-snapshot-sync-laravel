<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Snapshots disk
    |--------------------------------------------------------------------------
    | The filesystem disk holding the .sql / .sql.gz snapshots. Must match the
    | disk configured for spatie/laravel-db-snapshots (config/db-snapshots.php).
    */

    'disk' => env('DB_SNAPSHOT_SYNC_DISK', 'snapshots'),

    /*
    |--------------------------------------------------------------------------
    | Shared internal token
    |--------------------------------------------------------------------------
    | The bearer token the sync client sends and the internal API checks. Set a
    | distinct random value per environment; the local .env holds the token of
    | the source it syncs from (production by default).
    */

    'token' => env('INTERNAL_API_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Sync sources
    |--------------------------------------------------------------------------
    | Base URLs `snapshot:sync --source=<key>` can pull from. `prod` is an
    | accepted alias for `production`.
    */

    'sources' => [
        'production' => env('DB_SNAPSHOT_SYNC_PROD_URL'),
        'uat' => env('DB_SNAPSHOT_SYNC_UAT_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync behaviour
    |--------------------------------------------------------------------------
    */

    'sync' => [
        'allowed_environments' => ['local', 'development'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Source-side dump
    |--------------------------------------------------------------------------
    | When the source builds a fresh snapshot for a sync, the data in these
    | tables is skipped (schema is still dumped). These are framework caches
    | and transient queues a dev copy never needs — excluding them keeps the
    | dump small and fast without touching real domain tables. Applied as
    | `--exclude-table-data=<table>` on top of the connection's own dump flags,
    | for the sync dump only; a project's rollback snapshots are unaffected.
    |
    | Matched as pg_dump/mysqldump patterns, so listing a table that does not
    | exist is harmless.
    */

    'dump' => [
        'exclude_table_data' => [
            'cache',
            'cache_locks',
            'sessions',
            'jobs',
            'job_batches',
            'failed_jobs',
            'pulse_aggregates',
            'pulse_entries',
            'pulse_values',
            'telescope_entries',
            'telescope_entries_tags',
            'telescope_monitoring',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | snapshot:load flags
    |--------------------------------------------------------------------------
    | Passed through to spatie's snapshot:load. --stream is required for any
    | non-trivial dump (avoids loading the whole file into memory).
    */

    'load' => [
        'drop-tables' => true,
        'force' => true,
        'stream' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sanitizer rules (per driver)
    |--------------------------------------------------------------------------
    | Some lines a dump tool emits are valid on the source server but reject on
    | a developer's locally-installed client. The sanitizer strips them.
    |
    | Postgres: line-level filters, mutates the file in place.
    | MySQL: a sed pipeline, writes a separate .sanitized.sql.gz.
    |
    | When a new "broken on import" line appears, add a prefix/contains entry
    | (Postgres) or a sed expression (MySQL) — no code change needed.
    */

    'sanitizer' => [

        'pgsql' => [
            // Drop lines starting with any of these (psql meta-commands PG 17+).
            'drop_prefixes' => [
                '\\restrict',
                '\\unrestrict',
                'SET transaction_timeout',
            ],
            // Drop lines containing any of these (e.g. ' OWNER TO ' if you dump
            // without --no-owner).
            'drop_contains' => [],
            // Whole-content multiline regexes, e.g. stripping GRANT/REVOKE to a
            // remote-only role:
            // '/^(?:GRANT|REVOKE|ALTER DEFAULT PRIVILEGES)\b[^;]*"claude-[^"]*"[^;]*;\n?/m'
            'drop_patterns' => [],
        ],

        'mysql' => [
            // sed -E expressions. Strips DEFINER clauses that need SUPER /
            // SET_USER_ID to import, and downgrades SECURITY DEFINER views.
            'sed' => [
                's/DEFINER=`[^`]+`@`[^`]+`//g',
                's/SQL SECURITY DEFINER/SQL SECURITY INVOKER/g',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Internal snapshot API (source side)
    |--------------------------------------------------------------------------
    | The endpoints `snapshot:sync` calls on the source server. Enable only on
    | machines that should serve snapshots (production / UAT), not consumers.
    */

    'api' => [
        'enabled' => (bool) env('DB_SNAPSHOT_SYNC_API', false),
        'prefix' => 'internal',
        'middleware' => ['throttle:5,1'],
        // Filename fragments the API must never serve (a schema-only baseline,
        // or a MySQL .sanitized. intermediate). Match by substring, by name.
        'reject' => ['.sanitized.'],
    ],
];
