<?php

namespace App\Support\Database;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes the `app.current_access_role` PostgreSQL session setting,
 * the sibling of FilialeContext.
 *
 * Only the security-log policies created by
 * RowLevelSecurity::enableSecurityLog() read it: filiale answers "which tenant
 * is this", and this answers "with what authority", which the `audit_logs`
 * policy needs in order to let everyone append while letting only
 * admin/data_owner read.
 *
 * Kept as its own class rather than folded into FilialeContext because the two
 * are not interchangeable — a wrong filiale shows the wrong tenant's data, a
 * wrong role here escalates a privilege — and because every other table in the
 * schema depends on the filiale setting alone.
 *
 * As with FilialeContext, anything talking to the database outside an HTTP
 * request must publish a value itself; an unset role sees no audit rows.
 */
class AccessRoleContext
{
    /**
     * Publish $role for the remainder of the database session.
     *
     * Bound as a parameter through set_config() for the same reason
     * FilialeContext::set() does it: `SET` accepts no placeholders, and this
     * value originates from a user record.
     */
    public static function set(?string $role, ?string $connection = null): void
    {
        $conn = DB::connection($connection);

        if ($conn->getDriverName() !== 'pgsql') {
            return;
        }

        $conn->statement('select set_config(?, ?, false)', [
            RowLevelSecurity::ROLE_SETTING,
            (string) $role,
        ]);
    }

    /**
     * Clear the role. The policy reads an empty string as NULL, which matches
     * no entry in the allowed-roles list, so the audit log goes dark.
     */
    public static function forget(?string $connection = null): void
    {
        self::set('', $connection);
    }

    public static function current(?string $connection = null): ?string
    {
        $conn = DB::connection($connection);

        if ($conn->getDriverName() !== 'pgsql') {
            return null;
        }

        $value = $conn->scalar('select nullif(current_setting(?, true), \'\')', [
            RowLevelSecurity::ROLE_SETTING,
        ]);

        return $value === null ? null : (string) $value;
    }

    /**
     * Run $callback with $role published, restoring the previous value after.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function runAs(?string $role, Closure $callback, ?string $connection = null): mixed
    {
        $previous = self::current($connection);

        self::set($role, $connection);

        try {
            return $callback();
        } finally {
            self::set($previous, $connection);
        }
    }
}
