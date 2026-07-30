<?php

use App\Support\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Turns on PostgreSQL Row-Level Security for every filiale-scoped table.
 *
 * Add a table to STRICT_TABLES the moment it gains a `filiale_id` column — that
 * single line is the whole opt-in. No-ops on sqlite so the test suite still
 * boots on an in-memory database.
 */
return new class extends Migration
{
    /**
     * Fail-closed tables: invisible until SetTenantContext has published a
     * filiale on the connection.
     */
    private const STRICT_TABLES = [
        'procedures',
        'procedure_versions',
        'kaizen_reports',
        'quiz_results',
        'embeddings',
        'articles',
        'hr_requests',
    ];

    /**
     * Readable while no filiale context is set.
     *
     * `users` has to be in this list: Sanctum resolves the token owner *before*
     * the middleware can know which filiale to publish, so a fail-closed policy
     * here would make every authenticated request 401. Once the context is set
     * — i.e. for every query the application itself issues — the policy scopes
     * `users` exactly like the business tables.
     */
    private const CONTEXT_OPTIONAL_TABLES = [
        'users',
    ];

    public function up(): void
    {
        foreach (self::STRICT_TABLES as $table) {
            if (Schema::hasTable($table)) {
                RowLevelSecurity::enable($table);
            }
        }

        foreach (self::CONTEXT_OPTIONAL_TABLES as $table) {
            if (Schema::hasTable($table)) {
                RowLevelSecurity::enable($table, allowUnsetContext: true);
            }
        }
    }

    public function down(): void
    {
        foreach ([...self::STRICT_TABLES, ...self::CONTEXT_OPTIONAL_TABLES] as $table) {
            if (Schema::hasTable($table)) {
                RowLevelSecurity::disable($table);
            }
        }
    }
};
