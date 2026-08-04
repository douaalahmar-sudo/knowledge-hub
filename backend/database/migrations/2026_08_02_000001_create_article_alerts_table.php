<?php

use App\Support\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alerts raised by collaborators against a published article (cahier des
 * charges §7.2/§7.3) — the "signalement d'écart" loop.
 *
 * Deliberately a new table, NOT an extension of the legacy `kaizen_reports`:
 * that one is keyed to procedures, carries a different status vocabulary, and
 * is wired to an unrouted controller. This module is built alongside it, the
 * same way the article workflow was built alongside the original
 * knowledge-base articles rather than migrating them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('filiale_id')->constrained('filiales');

            // An alert dies with the article it describes: it is an account of
            // a discrepancy in *that* document and is meaningless without it.
            $table->foreignUuid('article_id')->constrained('articles')->cascadeOnDelete();

            // users.id is bigint, so foreignId — not the foreignUuid used for
            // filiale_id/article_id. Same split as the articles table.
            $table->foreignId('reported_by')->constrained('users');

            $table->enum('type', ['obsolescence', 'erreur', 'amelioration']);
            $table->enum('criticite', ['faible', 'moyenne', 'critique']);

            // The collaborator's account of the discrepancy. Required: an alert
            // with no description gives the Process Owner nothing to act on.
            $table->text('description');

            $table->enum('status', ['ouverte', 'en_cours', 'cloturee'])->default('ouverte');

            // The Process Owner ("Gardien du Temple", §6.1) who acknowledged it.
            // Null until the alert moves to en_cours.
            $table->foreignId('taken_by')->nullable()->constrained('users');

            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            // §7.3 Niveau 2 asks "does this article have an alert in progress?"
            // on every article read, and the role-filtered list in
            // ArticleAlertController::index() filters on reported_by.
            $table->index(['article_id', 'status']);
            $table->index('reported_by');
        });

        // Same fail-closed filiale_isolation policy as articles/procedures —
        // see RowLevelSecurity. No-ops on sqlite, which the test suite boots on.
        RowLevelSecurity::enable('article_alerts');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('article_alerts');
        Schema::dropIfExists('article_alerts');
    }
};
