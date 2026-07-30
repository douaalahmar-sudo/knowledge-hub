<?php

namespace App\Http\Controllers;

use App\Enums\ArticleCriticite;
use App\Enums\ArticleStatus;
use App\Enums\UserRole;
use App\Http\Requests\StoreArticleRequest;
use App\Http\Requests\UpdateArticleRequest;
use App\Models\Article;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * CRUD only — no status-transition endpoints (submit/validate-metier/
 * validate-qualite/archive) and no Drive file uploads yet. Both are separate
 * tasks layered on top of this.
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
