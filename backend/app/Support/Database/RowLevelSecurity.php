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
