<?php

namespace App\Http\Controllers;

use App\Enums\ArticleCriticite;
use App\Enums\ArticleFileFormat;
use App\Enums\ArticleStatus;
use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Http\Requests\RejectArticleRequest;
use App\Http\Requests\StoreArticleRequest;
use App\Http\Requests\UpdateArticleRequest;
use App\Http\Requests\UploadArticleFileRequest;
use App\Models\Article;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\GoogleDriveService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * CRUD, the workflow transitions (submit/validate-metier/validate-qualite/
 * reject), and the three Drive-backed file slots (pdf/infographie/video).
 */
class ArticleController extends Controller
{
    /**
     * §10.4 requires every consultation to be journalled, and §4.2 requires
     * archived versions to stay traceable there. Both are written through this
     * one service so no endpoint re-derives the actor or the IP — see
     * AuditLogger for why the address is not a parameter.
     */
    public function __construct(private AuditLogger $audit) {}

    /**
     * Filiale scoping needs nothing here: the RLS policy on `articles` already
     * confines every query on this connection to the caller's filiale. What
     * this method adds on top is the per-role narrowing — see scopeToRole().
     */
    public function index(Request $request): JsonResponse
    {
        // withCount feeds Article::isUnderRevision() (§7.3 Niveau 2) — one
        // extra query for the whole list rather than one per article, which is
        // what the accessor's exists() fallback would otherwise cost here.
        $query = Article::with('author:id,name,email')
            ->withCount('alertsEnCours')
            ->latest();

        $this->scopeToRole($query, $request->user());

        return response()->json($query->get(), 200);
    }

    public function show(Request $request, Article $article): JsonResponse
    {
        // RLS already let this row through (same filiale). What is checked here
        // is the same per-role slice index() applies, so an article that never
        // appears in a caller's list cannot be opened by pasting its id either.
        if (! $this->isVisibleTo($article, $request->user())) {
            // Journalled before aborting: a refused consultation is exactly
            // what a security log is for. The entry records the article the
            // caller asked for, and why they did not get it.
            $this->audit->log(AuditAction::ArticleAccessDenied, $article, [
                'endpoint' => 'articles.show',
                'reason' => 'outside_role_scope',
                'access_role' => $request->user()->access_role?->value,
                'status' => $article->status->value,
                'is_active_version' => $article->is_active_version,
            ]);

            $this->abortAsNotFound($article);
        }

        // §10.4. index() is deliberately not journalled: a list is a page of
        // titles, not a consultation of a document, and one row per list render
        // would bury the events that matter under navigation noise.
        $this->audit->log(AuditAction::ArticleViewed, $article, [
            'status' => $article->status->value,
            'version' => $article->version,
        ]);

        // loadCount rather than withCount: $article is already route-bound and
        // hydrated here. Without it Article::isUnderRevision() falls back to an
        // exists() query — correct either way, but this keeps the §7.3 banner
        // flag on the same footing as index().
        return response()->json(
            $article->load('author:id,name,email')->loadCount('alertsEnCours'),
            200
        );
    }

    public function store(StoreArticleRequest $request): JsonResponse
    {
        Gate::authorize('create-articles');

        $validated = $request->validated();
        $user = $request->user();

        $article = Article::create([
            'filiale_id' => $user->filiale_id,
            'title' => $validated['title'],
            'slug' => $this->uniqueSlug($validated['title']),
            'content_summary' => $validated['content_summary'] ?? null,
            'tags_metier' => $validated['tags_metier'] ?? [],
            'criticite' => $validated['criticite'] ?? ArticleCriticite::Note->value,
            'status' => ArticleStatus::Draft->value,
            'author_id' => $user->id,
            // Reassignable later (e.g. to whoever's actually accountable for
            // the data); defaulting to the author keeps this step optional now.
            'data_owner_id' => $user->id,
        ]);

        return response()->json($article->load('author:id,name,email'), 201);
    }

    /**
     * All three conditions — create-articles Gate, author, still-draft — are
     * enforced in UpdateArticleRequest::authorize(); by the time this runs,
     * that's already guaranteed.
     */
    public function update(UpdateArticleRequest $request, Article $article): JsonResponse
    {
        $article->fill($request->validated());
        $article->save();

        return response()->json($article->load('author:id,name,email'), 200);
    }

    /**
     * Articles are never hard-deleted — the "Zéro Doublon" versioning model
     * (parent_article_id / is_active_version) retires a version by archiving
     * it through the workflow, not by removing the row. $article is still
     * route-bound so a nonexistent id 404s before reaching this at all; the
     * block itself doesn't depend on which article it is.
     */
    public function destroy(Article $article): JsonResponse
    {
        return response()->json([
            'message' => 'Les articles ne sont pas supprimés directement : ils sont archivés via le workflow de validation.',
        ], 403);
    }

    /**
     * draft -> pending_metier. Only the author may submit their own article;
     * canTransitionTo() is still checked afterwards so a non-draft article
     * gets a specific "wrong state" message rather than silently succeeding.
     */
    public function submit(Request $request, Article $article): JsonResponse
    {
        if ($article->author_id !== $request->user()->id) {
            abort(403, 'Seul l\'auteur peut soumettre cet article pour validation.');
        }

        $this->assertCanTransition($article, ArticleStatus::PendingMetier);

        $previous = $article->status;

        $article->status = ArticleStatus::PendingMetier;
        $article->save();

        $this->logTransition(AuditAction::ArticleSubmitted, $article, $previous);

        return response()->json($article->load('author:id,name,email'), 200);
    }

    /**
     * pending_metier -> pending_qualite.
     */
    public function validateMetier(Request $request, Article $article): JsonResponse
    {
        Gate::authorize('validate-metier');

        $this->assertCanTransition($article, ArticleStatus::PendingQualite);

        $previous = $article->status;

        $article->status = ArticleStatus::PendingQualite;
        $article->validated_by_metier_id = $request->user()->id;
        $article->save();

        $this->logTransition(AuditAction::ArticleValidatedMetier, $article, $previous);

        return response()->json($article->load('author:id,name,email'), 200);
    }

    /**
     * pending_qualite -> published. Also retires whichever article is
     * currently the active version of this one's lineage (the "Zéro Doublon"
     * replacement: the old version becomes archived and invisible to
     * lecteurs, but the row — and its own validation history — stays intact).
     */
    public function validateQualite(Request $request, Article $article): JsonResponse
    {
        Gate::authorize('validate-qualite');

        $this->assertCanTransition($article, ArticleStatus::Published);

        $previous = $article->status;

        $archived = DB::transaction(function () use ($article, $request) {
            $retired = $this->archivePreviousActiveVersion($article);

            $article->status = ArticleStatus::Published;
            $article->validated_by_qualite_id = $request->user()->id;
            $article->published_at = now();
            $article->is_active_version = true;
            $article->save();

            return $retired;
        });

        // Journalled after the commit, not inside it. A failed INSERT inside a
        // PostgreSQL transaction aborts the whole transaction, and AuditLogger
        // swallows write failures by design — so logging in there could turn a
        // lost audit entry into a lost publication, which is the wrong way
        // round. The cost is that a crash between the two loses the entries;
        // the publication itself is still recoverable from the article row.
        $this->logTransition(AuditAction::ArticleValidatedQualite, $article, $previous);

        // §4.2: archived versions must stay "traçables dans les logs d'audit".
        // One entry per superseded version, pointing at the version itself
        // rather than at the article that replaced it — the trail is queried by
        // "what happened to this document", so it has to be filed under the
        // document it happened to.
        foreach ($archived as $retired) {
            $this->audit->log(AuditAction::ArticleArchived, $retired, [
                'old_status' => ArticleStatus::Published->value,
                'new_status' => ArticleStatus::Archived->value,
                'superseded_by' => $article->id,
                'superseded_by_version' => $article->version,
                'reason' => 'nouvelle_version_publiee',
            ]);
        }

        return response()->json($article->fresh()->load('author:id,name,email'), 200);
    }

    /**
     * pending_metier -> draft, or pending_qualite -> draft. Not a
     * canTransitionTo() move — the enum's chain is strictly forward, and a
     * rejection is deliberately the one path that isn't. Which role may do
     * this depends on which stage the article is currently sitting at; see
     * RejectArticleRequest for that state-dependent gate selection and the
     * `reason` field (accepted, not yet persisted — a future
     * alerts/notifications task).
     */
    public function reject(RejectArticleRequest $request, Article $article): JsonResponse
    {
        $previous = $article->status;

        $article->status = ArticleStatus::Draft;
        $article->save();

        // The `reason` is still not persisted on the article (see the docblock),
        // but it is recorded here: the audit trail is the one place it can be
        // kept today without inventing a column the workflow does not yet read.
        $this->logTransition(AuditAction::ArticleRejected, $article, $previous, [
            'reason' => $request->validated()['reason'] ?? null,
        ]);

        return response()->json($article->load('author:id,name,email'), 200);
    }

    /**
     * Author-and-draft-only (identical to update()'s restriction, enforced in
     * UploadArticleFileRequest) — overwrites whatever was already in that slot.
     * The old Drive file is deleted best-effort afterwards so superseded
     * uploads don't just accumulate there, same convention as
     * TriptychUploadController; a failure to delete it doesn't fail the
     * request, since the new upload has already succeeded and is already saved.
     */
    public function uploadFile(UploadArticleFileRequest $request, Article $article, string $format, GoogleDriveService $drive): JsonResponse
    {
        $folderId = config('services.google_drive.articles_folder_id');

        if (! $folderId) {
            throw new RuntimeException('GOOGLE_DRIVE_ARTICLES_FOLDER_ID is not configured.');
        }

        $column = ArticleFileFormat::from($format)->column();
        $previousFileId = $article->{$column};

        $article->{$column} = $drive->upload($request->file('file'), $folderId);
        $article->save();

        if ($previousFileId) {
            try {
                $drive->delete($previousFileId);
            } catch (Throwable) {
                // Best-effort cleanup only — see docblock above.
            }
        }

        return response()->json($article->fresh()->load('author:id,name,email'), 200);
    }

    /**
     * Same visibility rule as show(): a lecteur gets a 404 rather than the
     * file itself unless the article is published and the active version.
     * Content-Type comes from Drive's own stored metadata, not a guess based
     * on $format alone — "infographie" covers several real image types, and
     * nothing else records which one a given file actually is.
     * Content-Disposition is always inline, never attachment: no download
     * prompt, per spec §10.2.
     */
    public function retrieveFile(Request $request, Article $article, string $format, GoogleDriveService $drive): Response
    {
        $fileFormat = ArticleFileFormat::tryFrom($format);

        if (! $fileFormat) {
            abort(422, "Format de fichier inconnu : « {$format} ». Valeurs acceptées : pdf, infographie, video.");
        }

        // Same slice as show(). Without this the tightening there would be
        // cosmetic: the document itself is what is worth protecting, and this
        // endpoint streams it from a bare article id.
        if (! $this->isVisibleTo($article, $request->user())) {
            $this->audit->log(AuditAction::ArticleAccessDenied, $article, [
                'endpoint' => 'articles.files.retrieve',
                'format' => $fileFormat->value,
                'reason' => 'outside_role_scope',
                'access_role' => $request->user()->access_role?->value,
                'status' => $article->status->value,
                'is_active_version' => $article->is_active_version,
            ]);

            $this->abortAsNotFound($article);
        }

        $fileId = $article->{$fileFormat->column()};

        if (! $fileId) {
            $this->audit->log(AuditAction::ArticleAccessDenied, $article, [
                'endpoint' => 'articles.files.retrieve',
                'format' => $fileFormat->value,
                'reason' => 'format_absent',
            ]);

            abort(404, "Aucun fichier « {$fileFormat->label()} » n'a été téléversé pour cet article.");
        }

        $content = $drive->streamFile($fileId);
        $mimeType = $drive->getMimeType($fileId) ?? $fileFormat->fallbackMimeType();

        // §10.4, and the event the §10.3 watermark is the on-screen half of:
        // this is the moment the document itself reaches a reader. Logged after
        // the Drive fetch succeeds, so an entry means content was actually
        // served rather than merely requested — a failed fetch throws before
        // here and is a 500 in the application log, not a consultation.
        $this->audit->log(AuditAction::ArticleFileViewed, $article, [
            'format' => $fileFormat->value,
            'mime_type' => $mimeType,
            'drive_file_id' => $fileId,
            'status' => $article->status->value,
            'version' => $article->version,
        ]);

        return response($content)
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', 'inline');
    }

    /**
     * Belt-and-suspenders check shared by every transition endpoint: the role
     * gate should already rule out calling this from the wrong state, but if
     * it somehow doesn't, this turns that into a specific message instead of
     * a confusing silent no-op or an unrelated database error.
     */
    private function assertCanTransition(Article $article, ArticleStatus $target): void
    {
        if (! $article->status->canTransitionTo($target)) {
            abort(422, "Transition invalide : impossible de passer de « {$article->status->label()} » à « {$target->label()} ».");
        }
    }

    /**
     * The article superseded by $article, if any — either $article's direct
     * parent, or a sibling sharing that same parent_article_id (every version
     * of a lineage points at the same root, per the "Zéro Doublon" model).
     * A brand-new, never-versioned article has no parent_article_id and
     * nothing to archive here.
     *
     * Returns the rows it retired so validateQualite() can journal one §4.2
     * entry each. They are fetched before the mass update rather than after:
     * afterwards they no longer match the `is_active_version` predicate, and a
     * bulk update reports a count, not which rows it touched.
     *
     * @return \Illuminate\Support\Collection<int, Article>
     */
    private function archivePreviousActiveVersion(Article $article): \Illuminate\Support\Collection
    {
        $rootId = $article->parent_article_id;

        if (! $rootId) {
            return collect();
        }

        $query = Article::where('is_active_version', true)
            ->where('id', '!=', $article->id)
            ->where(function (Builder $query) use ($rootId) {
                $query->where('id', $rootId)
                    ->orWhere('parent_article_id', $rootId);
            });

        $retiring = (clone $query)->get();

        $query->update([
            'is_active_version' => false,
            'status' => ArticleStatus::Archived->value,
        ]);

        return $retiring;
    }

    /**
     * One §10.4 entry for a workflow transition.
     *
     * old_status/new_status live in the metadata of every transition even
     * though the action name already implies them: the action says what the
     * actor did, the pair says what the document went through, and a reader
     * reconstructing a lineage months later should not have to know the
     * workflow's shape to read its history.
     *
     * @param  array<string, mixed>  $extra
     */
    private function logTransition(
        AuditAction $action,
        Article $article,
        ArticleStatus $previous,
        array $extra = []
    ): void {
        $this->audit->log($action, $article, array_merge([
            'old_status' => $previous->value,
            'new_status' => $article->status->value,
            'version' => $article->version,
        ], $extra));
    }

    /**
     * Applied as a query constraint in index().
     */
    private function restrictToPublishedActive(Builder $query): void
    {
        $query->where('status', ArticleStatus::Published->value)
            ->where('is_active_version', true);
    }

    /**
     * Narrow the list to what this role is actually supposed to see.
     *
     * This has to live here and not in the Angular list. Everything this query
     * returns ends up in the HTTP response body, so filtering client-side
     * would hide other people's drafts from the screen while still shipping
     * them to every reader's browser — visible in the network tab. Until this
     * method existed, that is exactly what happened: index() restricted only
     * lecteurs, so a redacteur received every colleague's unpublished draft.
     *
     * Everyone keeps seeing published + current articles, which is the shared
     * knowledge base. Each role then gets the extra slice it works on:
     *   - redacteur                their own articles, at any stage
     *   - responsable_departement  the metier queue they validate
     *   - qualite                  the qualite queue they validate
     *   - admin / data_owner       everything, archived versions included
     *   - lecteur, or no access_role at all
     *                              nothing beyond published + current
     *
     * show() and retrieveFile() apply this same slice through isVisibleTo(),
     * so the list and the single-article endpoints cannot disagree: an article
     * a caller never sees listed cannot be opened — or have its PDF streamed —
     * by pasting its id. The validator flows are unaffected, because the stage
     * each validator acts on is part of their slice by construction
     * (responsable_departement sees pending_metier, qualite sees
     * pending_qualite), which is what their queue links depend on.
     */
    private function scopeToRole(Builder $query, User $user): void
    {
        // §6.1 makes the data_owner ("Gardien du Temple") accountable for the
        // filiale's whole corpus, which is not exercisable without seeing it.
        if ($user->hasRole([UserRole::Admin, UserRole::DataOwner])) {
            return;
        }

        $extra = match (true) {
            $user->hasRole(UserRole::Redacteur) => fn (Builder $q) => $q->where('author_id', $user->id),
            $user->hasRole(UserRole::ResponsableDepartement) => fn (Builder $q) => $q->where('status', ArticleStatus::PendingMetier->value),
            $user->hasRole(UserRole::Qualite) => fn (Builder $q) => $q->where('status', ArticleStatus::PendingQualite->value),
            // Lecteur, and any user whose access_role is null (hasRole() reads
            // that as "no role"), fall through to published + current only.
            default => null,
        };

        // Grouped, so the OR cannot escape and widen an unrelated constraint
        // added to this builder later.
        $query->where(function (Builder $scoped) use ($extra): void {
            $scoped->where(fn (Builder $q) => $this->restrictToPublishedActive($q));

            if ($extra !== null) {
                $scoped->orWhere($extra);
            }
        });
    }

    /**
     * Whether this caller's role slice contains this article — the
     * single-article counterpart to scopeToRole().
     *
     * Deliberately re-asks the database instead of re-deciding in PHP against
     * the loaded row. scopeToRole() is the one definition of who sees what, and
     * a second implementation of the same rules here is exactly the thing that
     * drifts: index() and show() would start disagreeing, and the disagreement
     * would be silent. The cost is one indexed `exists()` per single-article
     * request, against a primary key.
     *
     * The RLS policy still applies to this query, so an article from another
     * filiale cannot be resurrected by it — this narrows, it never widens.
     */
    /**
     * Refuse an out-of-scope article as though the row did not exist.
     *
     * A plain `abort(404)` is not enough. Laravel converts a failed
     * route-model binding into a 404 carrying "No query results for model
     * [App\Models\Article] <uuid>", and convertExceptionToArray() passes an
     * HttpException's message through even with `app.debug` off — so a bare
     * abort(404) answers with an *empty* message where a genuinely missing id
     * answers with that sentence. The difference is small and it is precisely
     * the signal a caller enumerating UUIDs is looking for: empty means "this
     * one exists, you just cannot have it".
     *
     * Throwing the same exception the binding would have thrown makes the two
     * responses byte-identical. Covered by
     * ArticleShowScopeTest::test_an_out_of_scope_article_is_indistinguishable_from_a_nonexistent_one.
     */
    private function abortAsNotFound(Article $article): never
    {
        throw (new ModelNotFoundException)->setModel(Article::class, [$article->getKey()]);
    }

    private function isVisibleTo(Article $article, User $user): bool
    {
        $query = Article::query()->whereKey($article->getKey());

        $this->scopeToRole($query, $user);

        return $query->exists();
    }

    /**
     * `slug` is unique across every filiale, but this query only sees rows in
     * the caller's own filiale — RLS filters it before uniqueness is ever
     * checked. A slug that looks free from here can still collide with
     * another filiale's article, so a check-then-insert loop can't be trusted;
     * a random suffix sidesteps needing one at all.
     */
    private function uniqueSlug(string $title): string
    {
        return Str::slug($title).'-'.Str::lower(Str::random(6));
    }
}
