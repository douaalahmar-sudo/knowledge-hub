<?php

namespace App\Http\Controllers;

use App\Enums\ArticleStatus;
use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\Article;
use App\Models\PrintAuthorization;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The §11.1 exception to §11's Hub-wide print ban.
 *
 * Two steps on purpose:
 *
 *   POST /v1/articles/{article}/print-authorizations   — authorize one print
 *   POST /v1/print-authorizations/{authorization}/consume — it went to paper
 *
 * A grant that is issued and never used is a different fact from a document
 * that left the building on paper, and only the second one puts a copy in the
 * world. Both are journalled; an investigator holding a recovered sheet needs
 * the second.
 *
 * The grant is short-lived and single-use (see the migration). It is not a
 * security boundary against the person holding it — they are authorized, and
 * paper outlives any timer, which is exactly why §11.1 puts a banner and a
 * 24-hour notice on the page. It is what stops "I printed one document once"
 * from quietly becoming "this account can print".
 */
class PrintAuthorizationController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Authorize one print of one article for the calling user.
     *
     * Self-service: the caller authorizes their own print, so `user_id` and
     * `granted_by` are the same person. The Gate is what makes that safe —
     * only admin and data_owner hold it — and the delegated variant needs no
     * schema change when a request/approval flow exists to drive it.
     */
    public function store(Request $request, Article $article): JsonResponse
    {
        Gate::authorize('authorize-print');

        $user = $request->user();

        // A draft or superseded version must not go to paper carrying a banner
        // that calls it the exclusive property of the company: the 24-hour
        // notice points readers at "la version officielle faisant foi", and a
        // printed draft would contradict it on the same sheet.
        if ($article->status !== ArticleStatus::Published || ! $article->is_active_version) {
            abort(422, 'Seule la version publiée et en vigueur d\'un article peut être imprimée.');
        }

        $authorization = PrintAuthorization::create([
            'filiale_id' => $article->filiale_id,
            'article_id' => $article->id,
            'user_id' => $user->id,
            'granted_by' => $user->id,
            'expires_at' => now()->addSeconds((int) config('security.print.grant_ttl_seconds')),
        ]);

        $this->audit->log(AuditAction::ArticlePrintAuthorized, $article, [
            'print_authorization_id' => $authorization->id,
            // The number that goes on the paper — recorded here so a recovered
            // sheet can be matched back even if the account is later renamed
            // or its matricule reassigned.
            'matricule' => $user->matricule,
            'granted_by' => $user->id,
            'expires_at' => $authorization->expires_at->toIso8601String(),
        ]);

        return response()->json([
            'id' => $authorization->id,
            'article_id' => $article->id,
            'expires_at' => $authorization->expires_at->toIso8601String(),
            // Everything the banner needs, resolved server-side: the client
            // must not be the source of the identity it stamps on paper.
            'matricule' => $user->matricule ?? $user->email,
            'holder_name' => $user->name,
        ], 201);
    }

    /**
     * Mark the grant used, at the moment the print dialogue actually opens.
     *
     * Idempotency is deliberately NOT offered: a second call on a used grant is
     * a 422, because a second print is a second copy and needs its own
     * authorization. The client re-requests rather than replaying.
     */
    public function consume(Request $request, PrintAuthorization $authorization): JsonResponse
    {
        $user = $request->user();

        // RLS already confined this to the caller's filiale. A grant is
        // personal on top of that: holding someone else's id must not let you
        // print under their trace number.
        if ($authorization->user_id !== $user->id) {
            abort(403, 'Cette autorisation d\'impression appartient à un autre utilisateur.');
        }

        if (! $authorization->isUsable()) {
            abort(422, $authorization->used_at
                ? 'Cette autorisation d\'impression a déjà été utilisée.'
                : 'Cette autorisation d\'impression a expiré.');
        }

        $authorization->used_at = now();
        $authorization->save();

        $this->audit->log(AuditAction::ArticlePrinted, $authorization->article, [
            'print_authorization_id' => $authorization->id,
            'matricule' => $user->matricule,
        ]);

        return response()->json(['used_at' => $authorization->used_at->toIso8601String()], 200);
    }
}
