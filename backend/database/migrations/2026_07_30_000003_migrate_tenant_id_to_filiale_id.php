<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Replaces the `tenants` / `tenant_id` scoping with `filiales` / `filiale_id`.
 *
 * Every table that carried a `tenant_id` gains a `filiale_id uuid` pointing at
 * `filiales`, existing rows are remapped through the tenant -> filiale pairing
 * built at the top of up(), and the legacy column plus the `tenants` table are
 * dropped. From here on isolation is enforced by the RLS policies created in
 * 2026_07_30_000004, not by an Eloquent scope.
 */
return new class extends Migration
{
    /**
     * Tables that hold filiale-scoped business data.
     */
    private const SCOPED_TABLES = [
        'procedures',
        'procedure_versions',
        'kaizen_reports',
        'quiz_results',
        'embeddings',
        'articles',
        'hr_requests',
    ];

    /**
     * Tables whose `tenant_id` is a real foreign key (`foreignId(...)->constrained()`),
     * as opposed to `articles.tenant_id`, which is a bare indexed varchar with no
     * constraint behind it. Dropping a constrained column needs
     * dropConstrainedForeignId — plain dropColumn leaves a dangling FK on
     * PostgreSQL and makes SQLite's table-rebuild trip over an FK pointing at a
     * column that is disappearing in the same migration.
     */
    private const TENANT_ID_HAS_FOREIGN_KEY = [
        'users',
        'procedures',
        'procedure_versions',
        'kaizen_reports',
        'quiz_results',
        'embeddings',
        'hr_requests',
    ];

    public function up(): void
    {
        // 1. Mirror every legacy tenant into filiales, remembering the pairing.
        $map = $this->buildFilialeMap();

        // 2. users already has filiale_id (previous migration) — just backfill.
        $this->backfill('users', $map);
        $this->dropLegacyColumn('users');

        // 3. Same treatment for every business table.
        foreach (self::SCOPED_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $this->addFilialeColumn($table);
            $this->backfill($table, $map);
            $this->dropLegacyColumn($table);
        }

        // 4. The tenants table has no remaining references.
        Schema::dropIfExists('tenants');
    }

    public function down(): void
    {
        // The legacy bigint tenant ids are gone for good once this migration has
        // run; recreating them would invent data. Rolling back therefore only
        // restores the empty `tenants` shell and removes the filiale columns.
        if (! Schema::hasTable('tenants')) {
            Schema::create('tenants', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('plan')->default('free');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        foreach (self::SCOPED_TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'filiale_id')) {
                // Every filiale_id column was added via foreignUuid(...)->constrained(),
                // so dropping the FK and the column together is always correct here.
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropConstrainedForeignId('filiale_id');
                });
            }
        }
    }

    /**
     * Create one filiale per legacy tenant.
     *
     * @return array<string, string> legacy tenant id (as string) => filiale uuid
     */
    private function buildFilialeMap(): array
    {
        if (! Schema::hasTable('tenants')) {
            return [];
        }

        $map = [];
        $now = now();

        foreach (DB::table('tenants')->orderBy('id')->get() as $tenant) {
            $uuid = (string) Str::uuid();

            DB::table('filiales')->insert([
                'id' => $uuid,
                'name' => $tenant->name,
                'code' => null,
                'is_active' => $tenant->is_active ?? true,
                'created_at' => $tenant->created_at ?? $now,
                'updated_at' => $tenant->updated_at ?? $now,
            ]);

            $map[(string) $tenant->id] = $uuid;
        }

        return $map;
    }

    private function addFilialeColumn(string $table): void
    {
        if (Schema::hasColumn($table, 'filiale_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            // Nullable so the column can be added to tables that already hold
            // rows. RLS treats a NULL filiale_id as invisible to every session,
            // so an unmapped row fails closed rather than leaking.
            $blueprint->foreignUuid('filiale_id')
                ->nullable()
                ->constrained('filiales')
                ->nullOnDelete();

            $blueprint->index('filiale_id');
        });
    }

    /**
     * @param  array<string, string>  $map
     */
    private function backfill(string $table, array $map): void
    {
        if ($map === [] || ! Schema::hasColumn($table, 'tenant_id')) {
            return;
        }

        foreach ($map as $legacyId => $uuid) {
            // Bound as a string on purpose: `articles.tenant_id` is a varchar
            // while every other table stores a bigint, and PostgreSQL coerces
            // an untyped parameter to whichever the column actually is.
            DB::table($table)
                ->where('tenant_id', (string) $legacyId)
                ->update(['filiale_id' => $uuid]);
        }
    }

    private function dropLegacyColumn(string $table): void
    {
        if (! Schema::hasColumn($table, 'tenant_id')) {
            return;
        }

        // Dropping the FK and the column in the same Blueprint call (rather than
        // a separate raw ALTER TABLE beforehand) is what makes this portable:
        // SQLite rebuilds the whole table to drop a column, and it needs to see
        // both changes at once or the rebuild trips over a foreign key that
        // still points at a column which is about to disappear.
        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (in_array($table, self::TENANT_ID_HAS_FOREIGN_KEY, true)) {
                $blueprint->dropConstrainedForeignId('tenant_id');
            } else {
                // articles.tenant_id: a bare indexed varchar, no FK behind it —
                // but the index has to go in the same pass for the same reason
                // as the FK above.
                $blueprint->dropIndex(['tenant_id']);
                $blueprint->dropColumn('tenant_id');
            }
        });
    }
};
