<?php

namespace App\Http\Controllers;

use App\Models\Article;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ArticleController extends Controller
{
    private const CATEGORIES = 'news_announcements,onboarding_guides,policies_guidelines,hr_documentation';
    private const STATUSES = 'draft,published,archived';

    /**
     * List articles with optional category / search / status filters.
     * (Tenant scoping is applied automatically by the BelongsToTenant trait.)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Article::with('author:id,name,email')->latest();

        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('summary', 'like', "%{$search}%")
                  ->orWhere('content', 'like', "%{$search}%");
            });
        }

        return response()->json($query->get(), 200);
    }

    /**
     * Read a single article by slug (implicit route-model binding).
     */
    public function show(Article $article): JsonResponse
    {
        return response()->json($article->load('author:id,name,email'), 200);
    }

    /**
     * Create a new article (multipart — supports cover image + attachments).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'           => 'required|string|max:255',
            'category'        => 'required|in:' . self::CATEGORIES,
            'content'         => 'required|string',
            'status'          => 'nullable|in:' . self::STATUSES,
            'summary'         => 'nullable|string',
            'tags'            => 'nullable|array',
            'tags.*'          => 'string|max:50',
            'cover_image'     => 'nullable|image|max:10240',
            'cover_image_url' => 'nullable|string|max:2048',
            'attachments'     => 'nullable|array',
            'attachments.*'   => 'file|max:10240',
        ]);

        $status = $validated['status'] ?? 'draft';

        $article = Article::create([
            'author_id'            => $request->user()->id,
            'title'                => $validated['title'],
            'slug'                 => $this->uniqueSlug($validated['title']),
            'summary'              => $validated['summary'] ?? null,
            'content'              => $validated['content'],
            'category'             => $validated['category'],
            'tags'                 => $validated['tags'] ?? [],
            'status'               => $status,
            'published_at'         => $status === 'published' ? now() : null,
            'cover_image_url'      => $this->resolveCover($request, $validated['cover_image_url'] ?? null),
            'attachments'          => $this->storeAttachments($request),
            'reading_time_minutes' => $this->readingTime($validated['content']),
            // tenant_id is auto-filled by the BelongsToTenant trait.
        ]);

        return response()->json($article->load('author:id,name,email'), 201);
    }

    /**
     * Update an article by slug. Handles partial payloads (e.g. archive sends only status).
     */
    public function update(Request $request, Article $article): JsonResponse
    {
        $validated = $request->validate([
            'title'           => 'sometimes|required|string|max:255',
            'category'        => 'sometimes|required|in:' . self::CATEGORIES,
            'content'         => 'sometimes|required|string',
            'status'          => 'sometimes|required|in:' . self::STATUSES,
            'summary'         => 'nullable|string',
            'tags'            => 'nullable|array',
            'tags.*'          => 'string|max:50',
            'cover_image'     => 'nullable|image|max:10240',
            'cover_image_url' => 'nullable|string|max:2048',
            'attachments'     => 'nullable|array',
            'attachments.*'   => 'file|max:10240',
        ]);

        // Patch only the fields that were actually sent.
        foreach (['title', 'category', 'content', 'summary'] as $field) {
            if ($request->has($field)) {
                $article->{$field} = $validated[$field] ?? $article->{$field};
            }
        }

        if ($request->has('tags')) {
            $article->tags = $validated['tags'] ?? [];
        }

        if ($request->filled('content')) {
            $article->reading_time_minutes = $this->readingTime($validated['content']);
        }

        // Cover: an uploaded file or a pasted URL supersedes the existing value.
        if ($request->hasFile('cover_image') || $request->filled('cover_image_url')) {
            $article->cover_image_url = $this->resolveCover($request, $validated['cover_image_url'] ?? null);
        }

        // Append any newly uploaded attachments.
        if ($request->hasFile('attachments')) {
            $article->attachments = array_merge($article->attachments ?? [], $this->storeAttachments($request));
        }

        if ($request->has('status')) {
            $article->status = $validated['status'];
            // Stamp published_at the first time an article goes live.
            if ($validated['status'] === 'published' && !$article->published_at) {
                $article->published_at = now();
            }
        }

        $article->save();

        return response()->json($article->load('author:id,name,email'), 200);
    }

    // ---------------- Helpers ----------------

    /**
     * Build a globally-unique slug from the title.
     */
    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'article';
        $slug = $base;
        $i = 1;
        while (Article::withoutGlobalScopes()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$i);
        }
        return $slug;
    }

    /**
     * Store an uploaded cover image, or fall back to a provided URL.
     */
    private function resolveCover(Request $request, ?string $url): ?string
    {
        if ($request->hasFile('cover_image')) {
            $path = $request->file('cover_image')->store(
                'articles/' . $request->user()->tenant_id . '/covers',
                'public'
            );
            return Storage::disk('public')->url($path);
        }
        return $url ?: null;
    }

    /**
     * Persist uploaded attachments and return {name, url, size} descriptors.
     */
    private function storeAttachments(Request $request): array
    {
        if (!$request->hasFile('attachments')) {
            return [];
        }

        $folder = 'articles/' . $request->user()->tenant_id . '/attachments';

        return collect($request->file('attachments'))
            ->map(function (UploadedFile $file) use ($folder) {
                $path = $file->store($folder, 'public');
                return [
                    'name' => $file->getClientOriginalName(),
                    'url'  => Storage::disk('public')->url($path),
                    'size' => $file->getSize(),
                ];
            })
            ->all();
    }

    /**
     * Estimate reading time: word count / 200 WPM, HTML stripped, minimum 1.
     */
    private function readingTime(string $content): int
    {
        $words = str_word_count(strip_tags($content));
        return max(1, (int) ceil($words / 200));
    }
}
