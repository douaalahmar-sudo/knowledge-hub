<?php

namespace App\Http\Controllers;

use App\Enums\AlertStatus;
use App\Enums\ArticleStatus;
use App\Enums\UserRole;
use App\Http\Requests\ProcessArticleAlertRequest;
use App\Http\Requests\StoreArticleAlertRequest;
use App\Models\Article;
use App\Models\ArticleAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Alerts raised against articles — cahier des charges §7.2 (reporting) and
 * §7.3 (the three-level treatment loop).
 *
 * Deliberately separate from the legacy KaizenController, which is unrouted
 * and keyed to procedures. Same relationship the article workflow has to the
 * original knowledge-base articles: built alongside, not on top.
 *
 * Filiale scoping needs nothing here — the RLS policy on `article_alerts`
 * confines every query on this connection to the caller's filiale, exactly as
 * it does for `articles`. The only restrictions applied in the app itself are
 * role-based.
 *
 * NOTIFICATIONS (§7.3 Niveau 1 push to the Process Owner, Niveau 3 closure
 * notice to the reporter) are out of scope and intentionally absent: this
 * project has no notification infrastructure yet. Each transition below is a
 * single method that already resolves both parties — `reported_by` and
 * `taken_by` — so a notification layer hooks in at those three points without
 * restructuring anything.
 */
class ArticleAlertController extends Controller
{
    /**
     * §7.2: "tout collaborateur" may report a discrepancy, so there is no Gate
     * here — authentication is the only bar.
     *
     * The one visibility rule mirrors ArticleController::show(): a lecteur who
     * cannot see a draft must not be able to file a report against it either,
     * and 404 rather than 403 for the same reason — the article simply is not
     * there for them.
     */
    public function store(StoreArticleAlertRequest $request, Article $article): JsonResponse
    {
        $user = $request->user();

        if ($user->hasRole(UserRole::Lecteur) && ! $this->isPublishedActive($article)) {
            abort(404);
        }

        $alert = ArticleAlert::create([
            // Taken from the article, not the user: an alert belongs to the
            // document it describes. RLS guarantees the two match anyway — the
            // article was only readable because it is in the caller's filiale —
            // but sourcing it here keeps that explicit.
            'filiale_id' => $article->filiale_id,
            'article_id' => $article->id,
            'reported_by' => $user->id,
            'type' => $request->validated('type'),
            'criticite' => $request->validated('criticite'),
            'description' => $request->validated('description'),
            // `status` is not in $fillable; the column default ('ouverte') is
            // the single source of truth for where an alert starts.
        ]);

        // Without this the response carries `status: null`: the value comes
        // from a database DEFAULT, and the model instance create() hands back
        // only knows the attributes that were written. load() re-reads
        // relations, not columns, so it does not fix this on its own.
        $alert->refresh();

        return response()->json($this->withRelations($alert), 201);
    }

    /**
     * Process Owners and admins see every alert in their filiale; everyone else
     * sees only what they reported themselves.
     *
     * That split is the point of §7.3 — a collaborator tracks their own
     * signalement through to closure, while the "Gardien du Temple" (§6.1)
     * needs the whole queue. RLS has already limited both to one filiale.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ArticleAlert::with([
            'article:id,title,slug,status',
            'reportedBy:id,name,email',
            'takenBy:id,name,email',
        ])->latest();

        if (! $request->user()->can('process-article-alerts')) {
            $query->where('reported_by', $request->user()->id);
        }

        return response()->json($query->get(), 200);
    }

    /**
     * ouverte -> en_cours (§7.3 Niveau 2). Records who picked it up and when,
     * which is also what raises the "révision opérationnelle" banner on the
     * article — see Article::alertsEnCours().
     */
    public function acknowledge(ProcessArticleAlertRequest $request, ArticleAlert $alert): JsonResponse
    {
        $this->assertCanTransition($alert, AlertStatus::EnCours);

        $alert->status = AlertStatus::EnCours;
        $alert->taken_by = $request->user()->id;
        $alert->acknowledged_at = now();
        $alert->save();

        return response()->json($this->withRelations($alert), 200);
    }

    /**
     * en_cours -> cloturee (§7.3 Niveau 3). `taken_by` is deliberately left as
     * it was: closing does not reassign the alert, and overwriting it would
     * erase who actually handled the revision.
     */
    public function close(ProcessArticleAlertRequest $request, ArticleAlert $alert): JsonResponse
    {
        $this->assertCanTransition($alert, AlertStatus::Cloturee);

        $alert->status = AlertStatus::Cloturee;
        $alert->closed_at = now();
        $alert->save();

        return response()->json($this->withRelations($alert), 200);
    }

    /**
     * Refuses anything the AlertStatus chain forbids — closing an alert nobody
     * acknowledged, re-acknowledging one already in progress, touching a closed
     * one. 422 with the French labels, same shape as
     * ArticleController::assertCanTransition().
     */
    private function assertCanTransition(ArticleAlert $alert, AlertStatus $target): void
    {
        if (! $alert->status->canTransitionTo($target)) {
            abort(422, "Transition invalide : impossible de passer de « {$alert->status->label()} » à « {$target->label()} ».");
        }
    }

    /** The same payload shape for every response this controller returns. */
    private function withRelations(ArticleAlert $alert): ArticleAlert
    {
        return $alert->load([
            'article:id,title,slug,status',
            'reportedBy:id,name,email',
            'takenBy:id,name,email',
        ]);
    }

    /** Mirrors ArticleController's rule of the same name. */
    private function isPublishedActive(Article $article): bool
    {
        return $article->status === ArticleStatus::Published && $article->is_active_version;
    }
}
