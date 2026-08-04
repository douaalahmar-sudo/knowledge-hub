<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Http\Requests\IndexAuditLogRequest;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;

/**
 * Read access to the journal d'audit (cahier des charges §10.4).
 *
 * Read-only on purpose, and not by omission: there is no store(), update() or
 * destroy() here because entries are written by AuditLogger from the action
 * being audited, and because the table itself refuses UPDATE and DELETE — the
 * RLS policy set on `audit_logs` creates no policy for either command, so they
 * match no rows whoever issues them. A destroy() endpoint could not work even
 * if someone added one, which is the intended property.
 *
 * ## Access model
 *
 * Two independent layers, deliberately not one:
 *
 *  - The `view-audit-logs` Gate (admin/data_owner) guards this endpoint, in
 *    IndexAuditLogRequest, and produces the French 403.
 *  - The RLS policy grants SELECT on the table to those same two access_roles
 *    and to nobody else, filiale-scoped. A lecteur reaching this data through
 *    some other query — a future report, an injection point, a psql session as
 *    `kh_app` — still sees nothing.
 *
 * The Gate alone would be a UI-level restriction on a security log; the policy
 * alone would return an empty list with a 200 instead of an honest 403.
 */
class AuditLogController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Filterable by user, action, date range and filiale, newest first.
     *
     * No filiale predicate is applied unless one is asked for: the RLS policy
     * already confines this query to the caller's filiale, exactly as it does
     * for articles and alerts. The `filiale_id` filter is therefore only ever
     * able to *narrow* — passing another filiale's id returns an empty page
     * rather than that filiale's trail, which is the correct and deliberately
     * unhelpful answer.
     */
    public function index(IndexAuditLogRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $query = AuditLog::with([
            'user:id,name,email,matricule',
        ])->latest('created_at');

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (isset($filters['filiale_id'])) {
            $query->where('filiale_id', $filters['filiale_id']);
        }

        // Inclusive on both ends. `from`/`to` are whole timestamps, so a caller
        // passing a bare date gets midnight — documented in the API rather than
        // silently widened here, since guessing "they meant the whole day" would
        // make the boundary of an audit query depend on how it was spelled.
        if (isset($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        $page = $query->paginate($filters['per_page'] ?? 50);

        // Reading the trail is itself journalled: a log that cannot say who
        // consulted it has a blind spot exactly where it matters most. The
        // filters go into the metadata — "who looked at this user's history"
        // is a different question from "who opened the log".
        $this->audit->log(AuditAction::AuditLogViewed, null, [
            'filters' => $filters === [] ? null : $filters,
            'result_count' => $page->total(),
        ]);

        return response()->json($page, 200);
    }
}
