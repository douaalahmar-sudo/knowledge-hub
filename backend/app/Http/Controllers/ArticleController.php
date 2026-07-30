<?php

namespace App\Http\Controllers;

use App\Enums\ArticleCriticite;
use App\Enums\ArticleFileFormat;
use App\Enums\ArticleStatus;
use App\Enums\UserRole;
use App\Http\Requests\RejectArticleRequest;
use App\Http\Requests\StoreArticleRequest;
use App\Http\Requests\UpdateArticleRequest;
use App\Http\Requests\UploadArticleFileRequest;
use App\Models\Article;
use App\Services\GoogleDriveService;
use Illuminate\Database\Eloquent\Builder;
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
     * Filiale scoping needs nothing here: the RLS policy on `articles` already
     * confines every query on this connection to the caller's filiale. The
     * only extra restriction applied in the app itself is role-based — a
     * lecteur only ever sees published, current-version articles.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Article::with('author:id,name,email')->latest();

        if ($request->user()->hasRole(UserRole::Lecteur)) {
            $this->restrictToPublishedActive($query);
        }

        return response()->json($query->get(), 200);
    }

    public function show(Request $request, Article $article): JsonResponse
    {
        // RLS already let this row through (same filiale); a lecteur asking
        // for a draft/superseded article by id still shouldn't see it. 404
        // rather than 403 — same as index(), it's simply not there for them.
        if ($request->user()->hasRole(UserRole::Lecteur) && ! $this->isPublishedActive($article)) {
            abort(404);
        }

        return response()->json($article->load('author:id,name,email'), 200);
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

        $article->status = ArticleStatus::PendingMetier;
        $article->save();

        return response()->json($article->load('author:id,name,email'), 200);
    }

    /**
     * pending_metier -> pending_qualite.
     */
    public function validateMetier(Request $request, Article $article): JsonResponse
    {
        Gate::authorize('validate-metier');

        $this->assertCanTransition($article, ArticleStatus::PendingQualite);

        $article->status = ArticleStatus::PendingQualite;
        $article->validated_by_metier_id = $request->user()->id;
        $article->save();

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

        DB::transaction(function () use ($article, $request) {
            $this->archivePreviousActiveVersion($article);

            $article->status = ArticleStatus::Published;
            $article->validated_by_qualite_id = $request->user()->id;
            $article->published_at = now();
            $article->is_active_version = true;
            $article->save();
        });

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
        $article->status = ArticleStatus::Draft;
        $article->save();

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

        if ($request->user()->hasRole(UserRole::Lecteur) && ! $this->isPublishedActive($article)) {
            abort(404);
        }

        $fileId = $article->{$fileFormat->column()};

        if (! $fileId) {
            abort(404, "Aucun fichier « {$fileFormat->label()} » n'a été téléversé pour cet article.");
        }

        $content = $drive->streamFile($fileId);
        $mimeType = $drive->getMimeType($fileId) ?? $fileFormat->fallbackMimeType();

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
     */
    private function archivePreviousActiveVersion(Article $article): void
    {
        $rootId = $article->parent_article_id;

        if (! $rootId) {
            return;
        }

        Article::where('is_active_version', true)
            ->where('id', '!=', $article->id)
            ->where(function (Builder $query) use ($rootId) {
                $query->where('id', $rootId)
                    ->orWhere('parent_article_id', $rootId);
            })
            ->update([
                'is_active_version' => false,
                'status' => ArticleStatus::Archived->value,
            ]);
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
     * Same rule as restrictToPublishedActive(), checked against an
     * already-loaded row instead of applied to a query — used by show().
     */
    private function isPublishedActive(Article $article): bool
    {
        return $article->status === ArticleStatus::Published && $article->is_active_version;
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
