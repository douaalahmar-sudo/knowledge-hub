<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\SecurityAlertType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\SecurityAlert;
use App\Support\Database\AccessRoleContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * §10.4's "aspiration de données" detector: an abnormal volume of documents
 * opened in a reduced interval — the spec's example being more than 30 in under
 * 2 minutes.
 *
 * ## Why this runs inline and not on a schedule
 *
 * The clause says the system must *lever* an alert on detecting the pattern. A
 * batch job introduces exactly the delay the alert exists to avoid: an
 * aspiration that runs for two minutes is finished long before a five-minute
 * cron notices, and the alert then documents a completed exfiltration instead
 * of interrupting one. So detection happens where the evidence is written — see
 * AuditLogger, which calls this after journalling a consultation.
 *
 * The cost is one indexed COUNT per document view. `audit_logs` carries
 * (user_id, action, created_at) precisely for this query; see that migration.
 * If it ever does become a problem, config/security.php has a kill switch.
 *
 * ## Why it elevates the database role
 *
 * The detector runs inside the request of the user being detected — typically a
 * lecteur, who by design cannot SELECT `audit_logs` at all (the RLS policy
 * grants reads to admin/data_owner only). Counting their own consultations is
 * therefore impossible as themselves: without the elevation below, this class
 * would silently count zero and §10.4 would never fire in production while
 * every sqlite test passed.
 *
 * The elevation is deliberately the narrowest available: AccessRoleContext
 * publishes `admin` for the duration of one closure containing nothing but the
 * count and the suppression lookup, and restores the previous value in a
 * finally. It is system code acting as the system, in the same spirit as
 * FilialeContext::runAs() for queue workers — but it IS a privilege escalation
 * inside a user's request, so it is confined to this one method and must stay
 * that way. Anything added inside that closure inherits admin rights.
 *
 * A SECURITY DEFINER function owned by the table owner is the textbook
 * alternative and would move the privilege into the database. It was not built
 * because it puts a second, hand-maintained copy of the query in raw SQL that
 * no PHP test can reach; this is revisitable if more detectors appear.
 */
class SecurityAnomalyDetector
{
    /**
     * The actions that count as opening a document. Deliberately not every
     * audited action: a workflow transition or a denied read is not a
     * consultation, and counting them would let an editor's normal afternoon
     * trip a data-exfiltration alarm.
     *
     * ArticleAccessDenied is excluded for a subtler reason — a script probing
     * ids generates denials, and that is a different attack with a different
     * response. Worth its own detector, not a dilution of this one.
     *
     * @return list<string>
     */
    public static function consultationActions(): array
    {
        return [
            AuditAction::ArticleViewed->value,
            AuditAction::ArticleFileViewed->value,
        ];
    }

    public static function isConsultation(string $action): bool
    {
        return in_array($action, self::consultationActions(), true);
    }

    /**
     * Inspect the trail behind a freshly-written consultation entry and raise
     * an alert if the volume crosses the configured threshold.
     *
     * @return SecurityAlert|null  The alert, or null if nothing was raised.
     */
    public function inspect(AuditLog $entry): ?SecurityAlert
    {
        if (! config('security.anomaly.enabled') || ! $entry->user_id) {
            return null;
        }

        $threshold = (int) config('security.anomaly.threshold');
        $windowSeconds = (int) config('security.anomaly.window_seconds');

        // A misconfigured threshold of 0 would alert on every single view.
        if ($threshold < 1 || $windowSeconds < 1) {
            return null;
        }

        // Rolling, not calendar: always "the last N seconds from now", so a
        // burst straddling a minute boundary is counted as one burst.
        $since = now()->subSeconds($windowSeconds);

        try {
            return AccessRoleContext::runAs(UserRole::Admin->value, function () use ($entry, $threshold, $windowSeconds, $since) {
                $count = AuditLog::where('user_id', $entry->user_id)
                    ->whereIn('action', self::consultationActions())
                    ->where('created_at', '>=', $since)
                    ->count();

                // "plus de 30" — 30 is acceptable, the 31st is not.
                if ($count <= $threshold) {
                    return null;
                }

                // One alert per window, not one per consultation: past the
                // threshold every further view would otherwise raise another,
                // and a hundred rows describing one incident is how a real
                // signal gets buried.
                if ($this->alreadyRaised($entry, $since)) {
                    return null;
                }

                return SecurityAlert::create([
                    'filiale_id' => $entry->filiale_id,
                    'user_id' => $entry->user_id,
                    'alert_type' => SecurityAlertType::ExcessiveDocumentAccess->value,
                    'details' => [
                        'observed_count' => $count,
                        // Recorded per-alert because both are configurable: an
                        // alert read later must carry the settings it was
                        // judged against, not whatever is configured by then.
                        'threshold' => $threshold,
                        'window_seconds' => $windowSeconds,
                        'window_started_at' => $since->toIso8601String(),
                        'ip_address' => $entry->ip_address,
                        'triggering_audit_log_id' => $entry->id,
                    ],
                ]);
            });
        } catch (Throwable $e) {
            // Same trade as AuditLogger: a consultation the user is entitled to
            // must not fail because the detector did. Logged so a detector that
            // has stopped working is visible rather than merely quiet — which,
            // for a security control, is the failure mode that matters.
            Log::error('Security anomaly detection failed (§10.4).', [
                'user_id' => $entry->user_id,
                'audit_log_id' => $entry->id,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Has this user already tripped this detector inside the current window?
     *
     * Runs elevated (see inspect()) — `security_alerts` is admin-read, so the
     * subject of the alert cannot see it, which is the point.
     */
    private function alreadyRaised(AuditLog $entry, \Illuminate\Support\Carbon $since): bool
    {
        return SecurityAlert::where('user_id', $entry->user_id)
            ->where('alert_type', SecurityAlertType::ExcessiveDocumentAccess->value)
            ->where('created_at', '>=', $since)
            ->exists();
    }
}
