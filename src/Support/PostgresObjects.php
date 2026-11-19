<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Support;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * The DROP statements that clear a PostgreSQL database for a pg_dump to load
 * into it again: every table, view, materialized view, sequence, foreign table,
 * enum/composite/range type, domain, function, procedure, aggregate, collation
 * and text search configuration/dictionary in the connection's schemas — the
 * objects pg_dump recreates without IF NOT EXISTS. Laravel's dropAllTables()
 * clears tables only, so a dump holding a collation or an enum fails on its
 * second load into the same database ("collation … already exists").
 *
 * The objects are dropped one by one rather than with DROP SCHEMA … CASCADE,
 * which would also take the schema's owner, its grants, the default privileges
 * an owner role sets for the app role (ALTER DEFAULT PRIVILEGES IN SCHEMA …),
 * and every extension installed in it. Objects an extension owns are left to
 * the extension, and a table named in the connection's `dont_drop` (default
 * spatial_ref_sys) is kept, as Laravel's dropAllTables() keeps it.
 */
class PostgresObjects
{
    /**
     * @return list<string>
     */
    public static function dropStatements(Connection $db): array
    {
        if ($db->getDriverName() !== 'pgsql') {
            throw new RuntimeException("Connection [{$db->getName()}] is not a pgsql connection.");
        }

        $schemas = self::textArray($db->getSchemaBuilder()->getCurrentSchemaListing());
        $kept = self::textArray((array) ($db->getConfig('dont_drop') ?? ['spatial_ref_sys']));

        return array_map(strval(...), array_column($db->select(<<<'SQL'
            with schemas as (select unnest(?::text[]) as name),
            relations as (
                select 1 as ord, format('DROP %s IF EXISTS %I.%I CASCADE;',
                    case c.relkind when 'v' then 'VIEW' when 'm' then 'MATERIALIZED VIEW' when 'S' then 'SEQUENCE'
                        when 'f' then 'FOREIGN TABLE' else 'TABLE' end, n.nspname, c.relname) as statement
                from pg_class c join pg_namespace n on n.oid = c.relnamespace
                where n.nspname in (select name from schemas)
                  and c.relkind in ('r', 'p', 'v', 'm', 'S', 'f')
                  and not c.relispartition
                  and c.relname <> all(?::text[]) and (n.nspname || '.' || c.relname) <> all(?::text[])
                  and not exists (select 1 from pg_depend d where d.classid = 'pg_class'::regclass and d.objid = c.oid and d.deptype = 'e')
            ),
            types as (
                select 2 as ord, format('DROP %s IF EXISTS %I.%I CASCADE;', case t.typtype when 'd' then 'DOMAIN' else 'TYPE' end, n.nspname, t.typname)
                from pg_type t join pg_namespace n on n.oid = t.typnamespace
                where n.nspname in (select name from schemas)
                  and (t.typtype in ('e', 'd', 'r') or (t.typtype = 'c' and (select relkind from pg_class where oid = t.typrelid) = 'c'))
                  and not exists (select 1 from pg_depend d where d.classid = 'pg_type'::regclass and d.objid = t.oid and d.deptype = 'e')
            ),
            routines as (
                select 3 as ord, format('DROP %s IF EXISTS %I.%I(%s) CASCADE;',
                    case p.prokind when 'p' then 'PROCEDURE' when 'a' then 'AGGREGATE' else 'FUNCTION' end,
                    n.nspname, p.proname, pg_get_function_identity_arguments(p.oid))
                from pg_proc p join pg_namespace n on n.oid = p.pronamespace
                where n.nspname in (select name from schemas)
                  and not exists (select 1 from pg_depend d where d.classid = 'pg_proc'::regclass and d.objid = p.oid and d.deptype = 'e')
            ),
            collations as (
                select 4 as ord, format('DROP COLLATION IF EXISTS %I.%I CASCADE;', n.nspname, c.collname)
                from pg_collation c join pg_namespace n on n.oid = c.collnamespace
                where n.nspname in (select name from schemas)
                  and not exists (select 1 from pg_depend d where d.classid = 'pg_collation'::regclass and d.objid = c.oid and d.deptype = 'e')
            ),
            search_configs as (
                select 5 as ord, format('DROP TEXT SEARCH CONFIGURATION IF EXISTS %I.%I CASCADE;', n.nspname, c.cfgname)
                from pg_ts_config c join pg_namespace n on n.oid = c.cfgnamespace
                where n.nspname in (select name from schemas)
                  and not exists (select 1 from pg_depend d where d.classid = 'pg_ts_config'::regclass and d.objid = c.oid and d.deptype = 'e')
            ),
            search_dictionaries as (
                select 6 as ord, format('DROP TEXT SEARCH DICTIONARY IF EXISTS %I.%I CASCADE;', n.nspname, d.dictname)
                from pg_ts_dict d join pg_namespace n on n.oid = d.dictnamespace
                where n.nspname in (select name from schemas)
                  and not exists (select 1 from pg_depend e where e.classid = 'pg_ts_dict'::regclass and e.objid = d.oid and e.deptype = 'e')
            )
            select statement from (
                select * from relations union all select * from types union all select * from routines
                union all select * from collations union all select * from search_configs union all select * from search_dictionaries
            ) objects
            order by ord, statement
            SQL, [$schemas, $kept, $kept]), 'statement'));
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private static function textArray(array $values): string
    {
        return '{'.implode(',', array_map(fn (mixed $value): string => '"'.addcslashes((string) $value, '"\\').'"', $values)).'}';
    }
}
