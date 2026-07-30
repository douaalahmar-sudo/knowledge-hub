<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Filiale;
use App\Models\HrRequest;
use App\Models\KaizenReport;
use App\Models\Procedure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GlobalSearchController extends Controller
{
    private const PER_ENTITY_LIMIT = 20;

    /**
     * Unified cross-entity search.
     *
     * Query params: q (required), type (optional single entity filter),
     * author_id, date_from, date_to. None of the queries below filter by
     * filiale: the PostgreSQL RLS policies already restrict every one of these
     * tables to the filiale published by SetTenantContext.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q'         => 'required|string|min:1|max:255',
            'type'      => 'nullable|in:procedures,articles,kaizen,hr_requests',
            'author_id' => 'nullable|integer',
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date',
        ]);

        $q = trim($validated['q']);
        $type = $validated['type'] ?? null;

        // All results belong to the caller's filiale — resolve its name once.
        $tenantLocation = optional(Filiale::find($request->user()->filiale_id))->name;

        $results = [
            'procedures'  => $this->wants($type, 'procedures') ? $this->searchProcedures($q, $request, $tenantLocation) : [],
            'articles'    => $this->wants($type, 'articles')   ? $this->searchArticles($q, $request, $tenantLocation)   : [],
            'kaizen'      => $this->wants($type, 'kaizen')      ? $this->searchKaizen($q, $request, $tenantLocation)     : [],
            'hr_requests' => $this->wants($type, 'hr_requests') ? $this->searchHrRequests($q, $request, $tenantLocation) : [],
        ];

        return response()->json(['results' => $results], 200);
    }

    private function wants(?string $type, string $entity): bool
    {
        return $type === null || $type === $entity;
    }

    private function searchProcedures(string $q, Request $request, ?string $tenantLocation): array
    {
        $query = Procedure::with('creator:id,name')
            ->where(function ($sub) use ($q) {
                $sub->where('reference_code', 'like', "%{$q}%")
                    ->orWhere('name', 'like', "%{$q}%")
                    ->orWhere('module', 'like', "%{$q}%");
            });

        $this->applyCommonFilters($query, $request, 'created_by');

        return $query->latest()->limit(self::PER_ENTITY_LIMIT)->get()->map(fn ($p) => [
            'id'              => $p->id,
            'title'           => $p->name,
            'description'     => 'Réf. ' . $p->reference_code . ' · ' . $p->module,
            'entity_type'     => 'PROCEDURE',
            'author'          => $p->creator?->name,
            'tenant_location' => $tenantLocation,
            'created_at'      => $p->created_at,
            'url'             => '/dashboard/procedures',
            'badge'           => $p->status,
        ])->all();
    }

    private function searchKaizen(string $q, Request $request, ?string $tenantLocation): array
    {
        $query = KaizenReport::with(['procedure:id,name', 'user:id,name'])
            ->where(function ($sub) use ($q) {
                $sub->where('description', 'like', "%{$q}%")
                    ->orWhereHas('procedure', fn ($p) => $p->where('name', 'like', "%{$q}%"));
            });

        $this->applyCommonFilters($query, $request, 'user_id');

        return $query->latest()->limit(self::PER_ENTITY_LIMIT)->get()->map(fn ($k) => [
            'id'              => $k->id,
            'title'           => $k->procedure?->name ?? 'Écart Kaizen',
            'description'     => $k->description,
            'entity_type'     => 'KAIZEN',
            'author'          => $k->user?->name,
            'tenant_location' => $tenantLocation,
            'created_at'      => $k->created_at,
            'url'             => '/dashboard/kaizen',
            'badge'           => $k->criticality,
        ])->all();
    }

    private function searchArticles(string $q, Request $request, ?string $tenantLocation): array
    {
        $query = Article::with('author:id,name')
            ->where(function ($sub) use ($q) {
                $sub->where('title', 'like', "%{$q}%")
                    ->orWhere('summary', 'like', "%{$q}%")
                    ->orWhere('content', 'like', "%{$q}%")
                    ->orWhere('category', 'like', "%{$q}%")
                    ->orWhereJsonContains('tags', $q);
            });

        $this->applyCommonFilters($query, $request, 'author_id');

        return $query->latest()->limit(self::PER_ENTITY_LIMIT)->get()->map(fn ($a) => [
            'id'              => $a->id,
            'title'           => $a->title,
            'description'     => $a->summary ?: Str::limit(strip_tags($a->content), 160),
            'entity_type'     => 'ARTICLE',
            'author'          => $a->author?->name,
            'tenant_location' => $tenantLocation,
            'created_at'      => $a->created_at,
            'url'             => '/dashboard/knowledge-base/' . $a->slug,
            'badge'           => $a->status,
        ])->all();
    }

    private function searchHrRequests(string $q, Request $request, ?string $tenantLocation): array
    {
        // Privacy: a user only ever sees their own HR requests in search.
        $query = HrRequest::with('user:id,name')
            ->where('user_id', $request->user()->id)
            ->where(function ($sub) use ($q) {
                $sub->where('title', 'like', "%{$q}%")
                    ->orWhere('type', 'like', "%{$q}%");
            });

        $this->applyDateFilter($query, $request);

        return $query->latest()->limit(self::PER_ENTITY_LIMIT)->get()->map(fn ($h) => [
            'id'              => $h->id,
            'title'           => $h->title,
            'description'     => $h->description,
            'entity_type'     => 'HR_REQUEST',
            'author'          => $h->user?->name,
            'tenant_location' => $tenantLocation,
            'created_at'      => $h->created_at,
            'url'             => '/dashboard/hr-requests',
            'badge'           => $h->status,
        ])->all();
    }

    /**
     * Apply optional author_id + date range filters shared by most entities.
     */
    private function applyCommonFilters($query, Request $request, string $authorColumn): void
    {
        if ($request->filled('author_id')) {
            $query->where($authorColumn, $request->query('author_id'));
        }
        $this->applyDateFilter($query, $request);
    }

    private function applyDateFilter($query, Request $request): void
    {
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }
    }
}
