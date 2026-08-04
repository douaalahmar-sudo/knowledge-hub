<?php

use App\Enums\UserRole;
use App\Support\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The durable record of §10.4's automated security alert — "une alerte de
 * sécurité automatisée auprès de la DSI en cas de détection d'aspiration de
 * données".
 *
 * The "auprès de la DSI" half is NOT delivered by this table: this project has
 * no notification infrastructure (the same gap ArticleAlertController notes for
 * §7.3's Process Owner push). What exists here is the record and a read
 * endpoint for DSI-equivalent staff to work from; wiring an actual channel —
 * mail, Teams, a SIEM forward — is a follow-up that the Kaizen module and the
 * article alerts will need at the same time and should share.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('filiale_id')->constrained('filiales');

            /**
             * The account the alert is *about*, not the one who raised it —
             * nobody raises these, the system does. Not nullable: an alert with
             * no subject is not actionable, and every detector this table is
             * built for identifies a person.
             */
            $table->foreignId('user_id')->constrained('users');

            // App\Enums\SecurityAlertType. String, not a database enum, so a new
            // detector does not need a migration.
            $table->string('alert_type');

            /**
             * What tripped it: the count observed, the threshold and window in
             * force at the time, the ids consulted. The thresholds are recorded
             * per-alert on purpose — they are configurable (config/security.php),
             * so an alert read six months later must carry the settings it was
             * judged against rather than whatever is configured by then.
             */
            $table->jsonb('details')->nullable();

            $table->timestamp('created_at')->useCurrent();

            /**
             * For a triage workflow that does not exist yet.
             *
             * IMPORTANT: this column cannot currently be written. The RLS policy
             * set below is append-only — no UPDATE policy — so acknowledging an
             * alert needs an admin-scoped UPDATE policy added alongside the
             * endpoint that does it. The column ships now because the schema was
             * specified with it; the alternative was a follow-up migration that
             * would change the table's shape for no gain.
             */
            $table->timestamp('acknowledged_at')->nullable();

            // "Has this user tripped recently" — the suppression check the
            // detector runs before raising — and the endpoint's newest-first list.
            $table->index(['user_id', 'alert_type', 'created_at']);
            $table->index('created_at');
        });

        /**
         * Same append-only shape as `audit_logs`, with a narrower reader list:
         * admin alone, not admin+data_owner.
         *
         * §10.4 addresses these to the DSI, which is `admin` here. A data_owner
         * is accountable for their filiale's *documents* (§6.1) — that is why
         * they can read the consultation trail — but an alert naming a colleague
         * as a possible exfiltration risk is a personnel matter, not a document
         * one. Narrower is the reversible mistake; widening later is a policy
         * change, un-showing it is not.
         *
         * INSERT stays open to every role for the same reason it does on
         * audit_logs: the detector runs inside the request of the user being
         * detected. That user must be able to write the alert about themselves
         * and must never be able to read it.
         */
        RowLevelSecurity::enableSecurityLog('security_alerts', [
            UserRole::Admin->value,
        ]);
    }

    public function down(): void
    {
        RowLevelSecurity::disable('security_alerts');
        Schema::dropIfExists('security_alerts');
    }
};
