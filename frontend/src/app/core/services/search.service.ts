import { Injectable, inject } from '@angular/core';
import { Observable, of } from 'rxjs';
import { AuthService } from '../../services/auth.service';
import { SearchResponse, SearchQueryParams, SearchResultItem } from '../models/search-result.model';
import { STORE_KEYS, lsRead } from '../mock/local-store.util';
import { SEED_ARTICLES, SEED_HR_REQUESTS, SEED_PROCEDURES, SEED_KAIZEN } from '../mock/seed-data';

@Injectable({
  providedIn: 'root'
})
export class SearchService {
  private auth = inject(AuthService);

  /** Unified cross-entity search over the localStorage stores. */
  search(q: string, params: SearchQueryParams = {}): Observable<SearchResponse> {
    const term = (q || '').toLowerCase().trim();
    const tenant = this.auth.currentTenant()?.name ?? null;
    const userId = this.auth.currentUser()?.id;
    const has = (...vals: (string | null | undefined)[]) =>
      !term || vals.some(v => (v || '').toLowerCase().includes(term));
    const want = (type: string) => !params.type || params.type === type;

    const articles: SearchResultItem[] = want('articles')
      ? lsRead<any[]>(STORE_KEYS.articles, SEED_ARTICLES)
          .filter(a => has(a.title, a.summary, a.content, a.category, (a.tags || []).join(' ')))
          .map(a => ({
            id: a.id,
            title: a.title,
            description: a.summary || (a.content || '').replace(/<[^>]*>/g, ' ').slice(0, 160),
            entity_type: 'ARTICLE',
            author: a.author?.name,
            tenant_location: tenant,
            created_at: a.created_at,
            url: `/dashboard/knowledge-base/${a.slug}`,
            badge: a.status,
          }))
      : [];

    const procedures: SearchResultItem[] = want('procedures')
      ? lsRead<any[]>(STORE_KEYS.procedures, SEED_PROCEDURES)
          .filter(p => has(p.reference, p.name, p.module))
          .map(p => ({
            id: p.id,
            title: p.name,
            description: `Réf. ${p.reference} · ${p.module}`,
            entity_type: 'PROCEDURE',
            author: null,
            tenant_location: tenant,
            created_at: p.created_at,
            url: '/dashboard/procedures',
            badge: p.status,
          }))
      : [];

    const kaizen: SearchResultItem[] = want('kaizen')
      ? lsRead<any[]>(STORE_KEYS.kaizen, SEED_KAIZEN)
          .filter(k => has(k.description, k.procedure?.title))
          .map(k => ({
            id: k.id,
            title: k.procedure?.title || 'Écart Kaizen',
            description: k.description,
            entity_type: 'KAIZEN',
            author: k.submitter?.name,
            tenant_location: tenant,
            created_at: k.created_at,
            url: '/dashboard/kaizen',
            badge: k.criticality,
          }))
      : [];

    const hr_requests: SearchResultItem[] = want('hr_requests')
      ? lsRead<any[]>(STORE_KEYS.hrRequests, SEED_HR_REQUESTS)
          .filter(h => (!userId || h.user_id === userId) && has(h.title, h.type))
          .map(h => ({
            id: h.id,
            title: h.title,
            description: h.description,
            entity_type: 'HR_REQUEST',
            author: h.user_name,
            tenant_location: tenant,
            created_at: h.created_at,
            url: '/dashboard/hr-requests',
            badge: h.status,
          }))
      : [];

    return of({ results: { procedures, articles, kaizen, hr_requests } });
  }
}
