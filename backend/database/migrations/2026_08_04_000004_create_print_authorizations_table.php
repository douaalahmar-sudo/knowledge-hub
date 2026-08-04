<?php

use App\Support\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-print authorization for cahier des charges §11.1.
 *
 * §11 turns printing off across the entire Hub. This table is the *only* way
 * that default is lifted, and it lifts it one document, one person and a few
 * minutes at a time — never as a standing permission. That shape is what makes
 * §11.1's "COPIE TRACÉE" meaningful: a traced copy implies each copy has an
 * identity, and this row is it.
 *
 * The banner prints the holder's `matricule` (the spec's "ID MATRICULE
 * COMPTABLE"), which is what a recovered sheet of paper is matched back to;
 * this row is what that matricule is matched against, with the document, the
 * moment and the authorizer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_authorizations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('filiale_id')->constrained('filiales');

            // A grant is about one document. Cascade: a deleted article cannot
            // have an outstanding permission to print it.
            $table->foreignUuid('article_id')->constrained('articles')->cascadeOnDelete();

            /**
             * Who may print under this grant, and who authorized it.
             *
             * Both are the same person today — the flow is self-service, an
             * admin or data_owner authorizing their own print — and they are
             * still stored separately, because the delegated variant (a
             * privileged user granting to a named colleague) needs no schema
             * change, only a request/approval flow. Collapsing them into one
             * column would make that a migration.
             */
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('granted_by')->constrained('users');

            /** Beyond this the grant is dead. See config('security.print'). */
            $table->timestamp('expires_at');

            /**
             * Set when the print is actually sent to the browser's dialogue,
             * which also makes the grant single-use. Null means issued and not
             * (yet) used — a real and separate fact from a document that left
             * on paper.
             */
            $table->timestamp('used_at')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // The validity check on every consume: this user, this article,
            // still live.
            $table->index(['user_id', 'article_id', 'expires_at']);
        });

        /**
         * The ordinary filiale_isolation policy, NOT the append-only security-log
         * one used by audit_logs and security_alerts.
         *
         * Two reasons this table is different: the holder has to be able to READ
         * their own grant (they are usually a data_owner, not an admin), and
         * consuming it is an UPDATE, which the append-only policy set forbids
         * outright. The narrowing that matters here — you may only use your own
         * grant, and only before it expires — is a fact about the row rather
         * than about the caller's role, so it lives in the controller where it
         * can produce an accurate French message instead of an empty result.
         */
        RowLevelSecurity::enable('print_authorizations');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('print_authorizations');
        Schema::dropIfExists('print_authorizations');
    }
};
