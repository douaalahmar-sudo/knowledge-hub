<?php

namespace App\Support\Database;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes the `app.current_filiale_id` PostgreSQL session setting that
 * the RLS policies in App\Support\Database\RowLevelSecurity filter on.
 *
 * Anything that talks to the database outside an HTTP request — queue workers,
 * artisan commands, schedulers — must publish a filiale through runAs() or the
 * strict policies will (correctly) show it nothing.
 */
class FilialeContext
{
    /**
     * Publish $filialeId for the remainder of the database session.
     *
     * The value is bound as a query parameter rather than interpolated into a
     * `SET` statement: `SET` does not accept placeholders, so building that SQL
     * by hand would mean concatenating a user-derived value into a statement.
     * `set_config(..., is_local => false)` is the parameterisable equivalent and
     * lives for the whole session instead of only the current transaction.
     */
    public static function set(?string $filialeId, ?string $connection = null): void
    {
        $conn = DB::connection($connection);

        if ($conn->getDriverName() !== 'pgsql') {
            return;
        }

        $conn->statement('select set_config(?, ?, false)', [
            RowLevelSecurity::SETTING,
            (string) $filialeId,
        ]);
    }

    /**
     * Clear the context. The policies read an empty string as NULL, so every
     * strict table goes back to returning nothing.
     */
    public static function forget(?string $connection = null): void
    {
        self::set('', $connection);
    }

    /**
     * The filiale currently published on the connection, if any.
     */
    public static function current(?string $connection = null): ?string
    {
        $conn = DB::connection($connection);

        if ($conn->getDriverName() !== 'pgsql') {
            return null;
        }

        $value = $conn->scalar('select nullif(current_setting(?, true), \'\')', [
            RowLevelSecurity::SETTING,
        ]);

        return $value === null ? null : (string) $value;
    }

    /**
     * Run $callback with $filialeId published, restoring the previous context
     * afterwards. This is how jobs and commands enter a filiale.
     *
     * @template TReturn
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function runAs(?string $filialeId, Closure $callback, ?string $connection = null): mixed
    {
        $previous = self::current($connection);

        self::set($filialeId, $connection);

        try {
            return $callback();
        } finally {
            self::set($previous, $connection);
        }
    }
}
