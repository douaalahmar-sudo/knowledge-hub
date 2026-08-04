<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexSecurityAlertRequest;
use App\Models\SecurityAlert;
use Illuminate\Http\JsonResponse;

/**
 * Read access to §10.4's automated security alerts, for DSI-equivalent staff.
 *
 * ## This is not the "alerte auprès de la DSI"
 *
 * The clause asks the system to raise an alert *auprès de la DSI*. What is
 * built is the durable record and this endpoint to work from; nobody is
 * notified. This project has no notification infrastructure at all — the same
 * gap ArticleAlertController documents for §7.3's push to the Process Owner —
 * and inventing a delivery channel for one caller would guarantee two
 * incompatible ones once the Kaizen module needs the same thing.
 *
 * FOLLOW-UP, and it is a real gap rather than a nicety: until a channel exists,
 * an exfiltration alert waits for somebody to open this page. The detector
 * fires in real time (see SecurityAnomalyDetector) so the record is immediate
 * and complete; only the delivery is missing, and it should be built once for
 * §7.3 and §10.4 together.
 *
 * Read-only, like AuditLogController and for the same reasons: alerts are
 * raised by the detector, and `security_alerts` has no UPDATE or DELETE policy,
 * so the account an alert names cannot quietly clear it.
 */
class SecurityAlertController extends Controller
{
    /**
     * Newest first, filterable by subject, type and date range.
     *
     * No filiale predicate: the RLS policy already confines this to the
     * caller's filiale, as everywhere else in this codebase.
     *
     * Not journalled through AuditLogger, unlike the audit trail's own read
     * endpoint. Reading the audit log is a privileged look at everybody's
     * activity; reading the alert queue is the DSI doing the job the alert was
     * raised for, and journalling routine triage would add a row to the trail
     * for every refresh of a monitoring screen. The alert's own creation is
     * already on the trail, which is the event that matters.
     */
    public function index(IndexSecurityAlertRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $query = SecurityAlert::with('user:id,name,email,matricule')
            ->latest('created_at');

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['alert_type'])) {
            $query->where('alert_type', $filters['alert_type']);
        }

        if (isset($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        return response()->json($query->paginate($filters['per_page'] ?? 50), 200);
    }
}
