<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single way anything in this application writes to the journal d'audit
 * (cahier des charges §10.4).
 *
 * Call sites say what happened and to what; everything else — who, from where,
 * which filiale, when — is resolved here, once:
 *
 *     $this->audit->log(AuditAction::ArticleViewed, $article);
 *     $this->audit->log(AuditAction::ArticleArchived, $old, ['reason' => '…']);
 *
 * ## The IP is not a parameter
 *
 * It is read from the current request, from `$request->ip()` — the same call
 * AuthController::me() serves the §10.3 watermark from, so the address stamped
 * on a document on screen and the address recorded for that consultation are
 * the same value from the same source, and both depend on the trustProxies()
 * config in bootstrap/app.php rather than on two different derivations. A
 * caller cannot pass a different one, which is the point: an audit trail whose
 * origin field is supplied by the code being audited records what that code
 * chose to admit.
 *
 * ## Failures never break the audited action
 *
 * A consultation that cannot be logged is still a consultation the user is
 * entitled to; failing their request would turn a logging outage into an
 * outage of the Hub. Write failures are therefore swallowed and reported to
 * the application log, which is monitored — the trade is deliberate and it is
 * the one real weakness of this design: a silent gap in the trail is possible
 * where a hard failure would be undeniable. Anything stricter (a queue, an
 * outbox, refusing the request) is a bigger change than §10.4 asks for, and
 * the Log::error() below is what makes the gap detectable.
 *
 * ## Injected, not static
 *
 * The obvious shape is `AuditLogger::log(...)`, but a static call cannot be
 * substituted in a test that wants to assert on logging without a database,
 * and cannot take the request as a dependency — it would have to reach for the
 * container mid-call. It is injected instead — no binding is registered, the
 * container autowires it and hands it the active request — and the call site
 * reads `$this->audit->log(...)`, one character longer.
 */
class AuditLogger
{
    /**
     * The detector does NOT depend on this class in return — it creates the
     * alert row and hands it back, and the journal entry for that alert is
     * written here. A mutual dependency would be a container cycle; more to the
     * point, "everything that happens gets journalled here" stays true with the
     * arrow pointing one way.
     */
    public function __construct(
        private Request $request,
        private SecurityAnomalyDetector $detector,
    ) {}

    /**
     * Record one action.
     *
     * @param  AuditAction|string  $action    Prefer the enum; the string form
     *                                        exists for actions a future module
     *                                        adds without touching this one.
     * @param  Model|null  $auditable         What the action was performed on.
     * @param  array<string, mixed>  $metadata Extra context — old/new status,
     *                                        the format consulted, a denial
     *                                        reason. Must be JSON-serialisable.
     * @return AuditLog|null  The entry, or null if it could not be written.
     */
    public function log(AuditAction|string $action, ?Model $auditable = null, array $metadata = []): ?AuditLog
    {
        $user = $this->request->user();

        // Sourced from the record when there is one: an entry about an article
        // belongs to that article's filiale, which is also the only filiale the
        // RLS policy would accept the INSERT into. They can only differ if the
        // record was somehow loaded from outside the caller's tenant, in which
        // case the insert failing loudly (caught below) is the right outcome.
        $filialeId = $auditable?->getAttribute('filiale_id') ?? $user?->getAttribute('filiale_id');

        try {
            $entry = AuditLog::create([
                'filiale_id' => $filialeId,
                'user_id' => $user?->getAuthIdentifier(),
                'action' => $action instanceof AuditAction ? $action->value : $action,
                'auditable_type' => $auditable ? $auditable->getMorphClass() : null,
                // Cast: the column is a string so it can hold both uuid and
                // bigint keys (see the migration).
                'auditable_id' => $auditable ? (string) $auditable->getKey() : null,
                'ip_address' => $this->request->ip(),
                'metadata' => $metadata === [] ? null : $metadata,
            ]);

            $this->detectAnomalies($entry);

            return $entry;
        } catch (Throwable $e) {
            // See the docblock: the audited action must not fail because its
            // record could not be written. Everything needed to reconstruct the
            // lost entry by hand goes into the message.
            Log::error('Failed to write an audit log entry (§10.4).', [
                'action' => $action instanceof AuditAction ? $action->value : $action,
                'user_id' => $user?->getAuthIdentifier(),
                'auditable_type' => $auditable?->getMorphClass(),
                'auditable_id' => $auditable?->getKey(),
                'filiale_id' => $filialeId,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * §10.4's automated alert, checked the moment the consultation is recorded.
     *
     * Here rather than in the controllers because there are two consultation
     * endpoints today and there will be more (procedures are the next viewer),
     * and a detector that depends on each of them remembering to call it is a
     * detector that will eventually miss one. Everything that reaches the trail
     * passes through this method.
     *
     * Not recursive: the entry written for the alert carries
     * `security_alert.raised`, which is not a consultation action, so the
     * second call falls straight through the guard below.
     */
    private function detectAnomalies(AuditLog $entry): void
    {
        if (! SecurityAnomalyDetector::isConsultation((string) $entry->action)) {
            return;
        }

        $alert = $this->detector->inspect($entry);

        if (! $alert) {
            return;
        }

        // The alert row is the durable record; this puts it on the same
        // timeline as the consultations that caused it, so an investigator
        // reads one trail instead of joining two.
        $this->log(AuditAction::SecurityAlertRaised, $alert, [
            'alert_type' => $alert->alert_type,
            'subject_user_id' => $alert->user_id,
        ] + ($alert->details ?? []));
    }
}
