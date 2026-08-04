<?php

use App\Enums\UserRole;
use App\Support\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The journal d'audit required by cahier des charges §10.4 — "toutes les
 * actions de consultation sont consignées dans un journal d'audit (logs de
 * sécurité)" — and the table §4.2 needs in order for archived article versions
 * to be "traçables dans les logs d'audit".
 *
 * Append-only by construction: `created_at` with no `updated_at`, and an RLS
 * policy set that grants INSERT and SELECT but never UPDATE or DELETE. See
 * RowLevelSecurity::enableSecurityLog() for the access model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('filiale_id')->constrained('filiales');

            /**
             * The actor. Nullable only for entries with genuinely no user
             * behind them — a console command or queued job acting on the
             * system's own behalf. It never means "unknown": every path in the
             * application writes through AuditLogger, which takes the actor
             * from the authenticated request.
             *
             * No cascadeOnDelete, unlike article_alerts.reported_by: an audit
             * entry outlives the account it describes, which is the point. A
             * user cannot be hard-deleted while entries reference them, and
             * that constraint is deliberate.
             */
            $table->foreignId('user_id')->nullable()->constrained('users');

            // Free-form string rather than a database enum: App\Enums\AuditAction
            // is the vocabulary, and a new action must not need a migration.
            $table->string('action');

            /**
             * Polymorphic target, deliberately NOT uuidMorphs(): that pins the
             * key to uuid, which fits `articles` and `article_alerts` but not
             * `users`, whose id is bigint. A string key is the only shape that
             * takes both, today and for whatever is audited later — which is
             * the whole reason this is polymorphic.
             *
             * Nullable because not every action has a target: a future
             * 'session.login' or a bulk export answers "who did what" without
             * pointing at one row.
             *
             * No foreign key is possible on a polymorphic column in any case,
             * so nothing is lost there: referential integrity for these is the
             * application's job, and a dangling reference in an audit trail is
             * better than a deletion that rewrites history.
             */
            $table->string('auditable_type')->nullable();
            $table->string('auditable_id', 64)->nullable();

            // The address the action came from. Same value and same source as
            // the §10.3 watermark — see App\Services\AuditLogger.
            $table->ipAddress('ip_address')->nullable();

            /**
             * Extra context: old_status/new_status on a transition, the format
             * on a document consultation, the reason on a denial. `jsonb` on
             * PostgreSQL (queryable, indexable); Laravel's SQLite grammar
             * compiles both json and jsonb to `text`, so the test suite is
             * unaffected.
             */
            $table->jsonb('metadata')->nullable();

            /**
             * No timestamps(): an audit entry is a statement about a moment,
             * and an `updated_at` would imply it could be revised. useCurrent()
             * so a row written by raw SQL — a trigger, a psql session, a future
             * batch import — still gets a timestamp.
             */
            $table->timestamp('created_at')->useCurrent();

            // The three questions the read endpoint is built to answer: what
            // did this user do, who did this action, and what happened to this
            // document. Each is paired with created_at because every one of
            // them is asked over a date range.
            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['auditable_type', 'auditable_id', 'created_at']);
            $table->index('created_at');
        });

        /**
         * Everyone appends, only these two roles read, nobody updates or
         * deletes. data_owner is included because §6.1 makes the "Gardien du
         * Temple" accountable for their filiale's documents, and that
         * accountability is unexercisable without seeing who consulted them.
         */
        RowLevelSecurity::enableSecurityLog('audit_logs', [
            UserRole::Admin->value,
            UserRole::DataOwner->value,
        ]);
    }

    public function down(): void
    {
        RowLevelSecurity::disable('audit_logs');
        Schema::dropIfExists('audit_logs');
    }
};
