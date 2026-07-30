<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the new enum-based `access_role` column alongside the existing
 * `role_id` FK / `roles` table — deliberately not named `role`. `User::role()`
 * is already a `belongsTo(Role::class)` relationship, and Eloquent resolves a
 * plain attribute of the same name before falling back to a relationship
 * accessor. A `role` column would silently turn every `$user->role?->name`
 * call in AppServiceProvider's Gates and CheckRole middleware into `null`
 * instead of failing loudly, since none of those call sites use the
 * relationship's `->name` on a string without error suppression via `?->`.
 *
 * See App\Models\User::hasRole() for the new column's read path and
 * App\Enums\UserRole for its values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('access_role', [
                'redacteur',
                'responsable_departement',
                'qualite',
                'data_owner',
                'admin',
                'lecteur',
            ])->default('lecteur')->after('role_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('access_role');
        });
    }
};
