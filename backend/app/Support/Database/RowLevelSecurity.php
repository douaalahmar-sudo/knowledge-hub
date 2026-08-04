<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Reusable PostgreSQL Row-Level Security helper.
 *
 * Every filiale-scoped table gets the same treatment: RLS is enabled *and*
 * forced (so the table owner is subject to it too), and a single policy
 * restricts every row to the filiale carried in the `app.current_filiale_id`
 * session setting, which App\Http\Middleware\SetTenantContext writes on each
 * authenticated request.
 *
 * Isolation lives here, in the database — not in an Eloquent global scope.
 * A raw `DB::select('select * from articles')`, a `psql` session or a leaked
 * SQL-injection point all get filtered by the same policy.
 */
class RowLevelSecurity
{
    /** Name of the policy created on every scoped table. */
    public const POLICY = 'filiale_isolation';

    /** PostgreSQL session setting holding the active filiale. */
    public const SETTING = 'app.current_filiale_id';

    /** Policies created by enableSecurityLog(), in place of POLICY. */
    public const LOG_APPEND_POLICY = 'audit_append_only';

    public const LOG_READ_POLICY = 'audit_privileged_read';

    /**
     * PostgreSQL session setting holding the caller's access_role, written by
     * App\Http\Middleware\SetTenantContext alongside the filiale. Only the
     * security-log policies read it — see enableSecurityLog().
     */
    public const ROLE_SETTING = 'app.current_access_role';

    /**
     * Enable RLS on $table and (re)create the filiale isolation policy.
     *
     * @param  string  $table   Table that owns a `filiale_id uuid` column.
     * @param  bool    $allowUnsetContext
     *         When true the table stays readable while no filiale context is
     *         set. Required for `users`: Sanctum has to look the token owner up
     *         *before* the middleware can know which filiale to select.
     *         Business tables must leave this false so they fail closed.
     */
    public static function enable(string $table, bool $allowUnsetContext = false, ?string $connection = null): void
    {
        $conn = DB::connection($connection);

        // RLS is a PostgreSQL feature; the test suite also boots on sqlite.
        if ($conn->getDriverName() !== 'pgsql') {
            return;
        }

        $quoted = self::quote($table);
        $predicate = self::predicate($allowUnsetContext);

        $conn->statement("ALTER TABLE {$quoted} ENABLE ROW LEVEL SECURITY");

        // Without FORCE, the table owner (the role migrations run as) is exempt
        // and the policy would look active while enforcing nothing.
        $conn->statement("ALTER TABLE {$quoted} FORCE ROW LEVEL SECURITY");

        $conn->statement('DROP POLICY IF EXISTS '.self::POLICY." ON {$quoted}");

        // USING filters reads/updates/deletes; WITH CHECK stops a session from
        // writing a row into a filiale it is not currently acting as.
        $conn->statement(
            'CREATE POLICY '.self::POLICY." ON {$quoted} USING ({$predicate}) WITH CHECK ({$predicate})"
        );
    }

    /**
     * RLS for an append-only security log (cahier des charges §10.4).
     *
     * Three differences from enable(), each answering something a journal
     * d'audit needs that a business table does not:
     *
     * 1. INSERT is open to every session in the filiale. A lecteur consulting a
     *    document *is* the event being recorded, so if writing were privileged
     *    the log would only ever contain the actions of people allowed to read
     *    it — precisely inverted.
     *
     * 2. SELECT additionally requires the caller's access_role to be one of
     *    $readerRoles. This is the part that cannot be done in the application
     *    alone: a controller Gate protects the endpoint, whereas this protects
     *    the table, so an unrelated query, a leaked injection point or a psql
     *    session opened as `kh_app` sees nothing either.
     *
     * 3. No UPDATE or DELETE policy is created at all. Under FORCE RLS a
     *    command with no permissive policy matches no rows, so the table is
     *    append-only *in the database*: nobody — not an admin, not the
     *    application role — can rewrite or erase an entry through SQL. That is
     *    what makes the trail evidence rather than a mutable table. Only the
     *    table owner can undo it, and only with DDL.
     *
     * The role is read from a session setting rather than joined from `users`
     * because the policy must hold for any connection, including one that has
     * not authenticated through Laravel at all: an unset role fails closed the
     * same way an unset filiale does.
     *
     * @param  string[]  $readerRoles  access_role values allowed to SELECT.
     */
    public static function enableSecurityLog(string $table, array $readerRoles, ?string $connection = null): void
    {
        $conn = DB::connection($connection);

        if ($conn->getDriverName() !== 'pgsql') {
            return;
        }

        $quoted = self::quote($table);
        $filiale = self::predicate(false);

        // Quoted into the policy body rather than bound: CREATE POLICY takes no
        // parameters. Every value is a constant from App\Enums\UserRole chosen
        // by the migration, never user input, and the quoting is belt-and-braces.
        $roles = implode(', ', array_map(
            fn (string $role): string => "'".str_replace("'", "''", $role)."'",
            $readerRoles
        ));

        $roleContext = sprintf("nullif(current_setting('%s', true), '')", self::ROLE_SETTING);

        $conn->statement("ALTER TABLE {$quoted} ENABLE ROW LEVEL SECURITY");
        $conn->statement("ALTER TABLE {$quoted} FORCE ROW LEVEL SECURITY");

        $conn->statement('DROP POLICY IF EXISTS '.self::LOG_APPEND_POLICY." ON {$quoted}");
        $conn->statement('DROP POLICY IF EXISTS '.self::LOG_READ_POLICY." ON {$quoted}");
        // Also clear the standard policy, in case a table is ever converted.
        $conn->statement('DROP POLICY IF EXISTS '.self::POLICY." ON {$quoted}");

        // WITH CHECK only — an INSERT policy has no USING clause, since there is
        // no existing row to test. A session can only file entries against its
        // own filiale.
        $conn->statement(
            'CREATE POLICY '.self::LOG_APPEND_POLICY." ON {$quoted} FOR INSERT WITH CHECK ({$filiale})"
        );

        $conn->statement(
            'CREATE POLICY '.self::LOG_READ_POLICY." ON {$quoted} FOR SELECT USING ({$filiale} AND {$roleContext} IN ({$roles}))"
        );
    }

    /**
     * Drop the policy and disable RLS again (used by migration rollbacks).
     */
    public static function disable(string $table, ?string $connection = null): void
    {
        $conn = DB::connection($connection);

        if ($conn->getDriverName() !== 'pgsql') {
            return;
        }

        $quoted = self::quote($table);

        $conn->statement('DROP POLICY IF EXISTS '.self::POLICY." ON {$quoted}");
        $conn->statement('DROP POLICY IF EXISTS '.self::LOG_APPEND_POLICY." ON {$quoted}");
        $conn->statement('DROP POLICY IF EXISTS '.self::LOG_READ_POLICY." ON {$quoted}");
        $conn->statement("ALTER TABLE {$quoted} NO FORCE ROW LEVEL SECURITY");
        $conn->statement("ALTER TABLE {$quoted} DISABLE ROW LEVEL SECURITY");
    }

    /**
     * SQL expression yielding the active filiale, or NULL when unset.
     *
     * `current_setting(..., true)` returns NULL instead of raising when the
     * setting was never assigned; NULLIF maps the empty string to NULL as well,
     * which is what a reset context looks like.
     */
    public static function contextExpression(): string
    {
        return sprintf("nullif(current_setting('%s', true), '')", self::SETTING);
    }

    private static function predicate(bool $allowUnsetContext): string
    {
        $ctx = self::contextExpression();

        // filiale_id = NULL evaluates to NULL, i.e. "not visible" — an unset
        // context therefore hides every row rather than exposing them all.
        $match = "filiale_id = {$ctx}::uuid";

        return $allowUnsetContext
            ? "({$ctx} IS NULL OR {$match})"
            : $match;
    }

    private static function quote(string $table): string
    {
        return '"'.str_replace('"', '', $table).'"';
    }
}
