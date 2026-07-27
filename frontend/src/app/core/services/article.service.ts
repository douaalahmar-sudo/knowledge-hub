import { Injectable, inject } from '@angular/core';
import { Observable, of, throwError } from 'rxjs';
import { AuthService } from '../../services/auth.service';
import { STORE_KEYS, lsRead, lsWrite, uid, slugify } from '../mock/local-store.util';
import { SEED_ARTICLES } from '../mock/seed-data';

export type ArticleCategory =
  | 'news_announcements'
  | 'onboarding_guides'
  | 'policies_guidelines'
  | 'hr_documentation';

export type ArticleStatus = 'draft' | 'published' | 'archived';

export interface ArticleAttachment {
  name: string;
  url: string;
  size: number;
}

export interface Article {
  id?: string;
  title: string;
  slug?: string;
  summary?: string;
  content: string;
  category: ArticleCategory;
  tags?: string[];
  status: ArticleStatus;
  published_at?: string | null;
  tenant_id?: string;
  author_id?: number;
  author?: { id: number; name: string; email: string };
  cover_image_url?: string;
  attachments?: ArticleAttachment[];
  reading_time_minutes?: number;
  is_featured?: boolean;
  created_at?: string;
  updated_at?: string;
}

export interface ArticleQuery {
  category?: string;
  search?: string;
  status?: string;
  page?: number;
}

/** Fields collected from the editor form. */
export interface ArticlePayload {
  title: string;
  category: ArticleCategory | string;
  summary?: string;
  content: string;
  status: ArticleStatus | string;
  tags?: string[];
  cover_image_url?: string;
  coverImageFile?: File | null;
  attachmentFiles?: File[];
}

@Injectable({
  providedIn: 'root'
})
export class ArticleService {
  private auth = inject(AuthService);

  private all(): Article[] {
    return lsRead<Article[]>(STORE_KEYS.articles, SEED_ARTICLES);
  }

  // 1. List with optional category / search / status filters.
  getArticles(params: ArticleQuery = {}): Observable<any> {
    let list = this.all();
    if (params.status) list = list.filter(a => a.status === params.status);
    if (params.category) list = list.filter(a => a.category === params.category);
    if (params.search) {
      const q = params.search.toLowerCase();
      list = list.filter(a =>
        a.title.toLowerCase().includes(q) ||
        (a.summary || '').toLowerCase().includes(q) ||
        (a.content || '').toLowerCase().includes(q) ||
        (a.tags || []).some(t => t.toLowerCase().includes(q))
      );
    }
    return of(list);
  }

  // 2. Read one by slug.
  getArticleBySlug(slug: string): Observable<Article> {
    const found = this.all().find(a => a.slug === slug);
    return found ? of(found) : throwError(() => ({ error: { message: 'Article introuvable.' } }));
  }

  // 3. Create.
  createArticle(payload: ArticlePayload): Observable<Article> {
    const list = this.all();
    const user = this.auth.currentUser();
    const article: Article = {
      id: uid('art_'),
      title: payload.title,
      slug: slugify(payload.title) + '-' + Math.random().toString(36).slice(2, 6),
      summary: payload.summary,
      content: payload.content,
      category: payload.category as ArticleCategory,
      tags: payload.tags ?? [],
      status: payload.status as ArticleStatus,
      published_at: payload.status === 'published' ? new Date().toISOString() : null,
      author: { id: user?.id ?? 0, name: user?.name ?? 'Équipe RH', email: user?.email ?? '' },
      cover_image_url: payload.cover_image_url || '',
      attachments: (payload.attachmentFiles ?? []).map(f => ({ name: f.name, url: '#', size: f.size })),
      reading_time_minutes: this.estimateReadingTime(payload.content),
      created_at: new Date().toISOString(),
    };
    lsWrite(STORE_KEYS.articles, [article, ...list]);
    return of(article);
  }

  // 4. Update (partial — archive sends only status via a status-only payload).
  updateArticle(idOrSlug: string, payload: ArticlePayload): Observable<Article> {
    const list = this.all();
    const idx = list.findIndex(a => a.slug === idOrSlug || String(a.id) === String(idOrSlug));
    if (idx < 0) return throwError(() => ({ error: { message: 'Article introuvable.' } }));

    const current = list[idx];
    const updated: Article = {
      ...current,
      title: payload.title ?? current.title,
      summary: payload.summary ?? current.summary,
      content: payload.content ?? current.content,
      category: (payload.category as ArticleCategory) ?? current.category,
      tags: payload.tags ?? current.tags,
      status: (payload.status as ArticleStatus) ?? current.status,
      cover_image_url: payload.cover_image_url || current.cover_image_url,
      reading_time_minutes: payload.content ? this.estimateReadingTime(payload.content) : current.reading_time_minutes,
      published_at: payload.status === 'published' && !current.published_at ? new Date().toISOString() : current.published_at,
      attachments: [
        ...(current.attachments ?? []),
        ...(payload.attachmentFiles ?? []).map(f => ({ name: f.name, url: '#', size: f.size })),
      ],
      updated_at: new Date().toISOString(),
    };
    list[idx] = updated;
    lsWrite(STORE_KEYS.articles, list);
    return of(updated);
  }

  // 5. Archive.
  archiveArticle(idOrSlug: string): Observable<Article> {
    const list = this.all();
    const idx = list.findIndex(a => a.slug === idOrSlug || String(a.id) === String(idOrSlug));
    if (idx < 0) return throwError(() => ({ error: { message: 'Article introuvable.' } }));
    list[idx] = { ...list[idx], status: 'archived', updated_at: new Date().toISOString() };
    lsWrite(STORE_KEYS.articles, list);
    return of(list[idx]);
  }

  /** Client-side reading-time estimate (words / 200 WPM, HTML stripped). */
  estimateReadingTime(content: string | undefined | null): number {
    const text = (content ?? '').replace(/<[^>]*>/g, ' ');
    const words = text.trim().split(/\s+/).filter(Boolean).length;
    return Math.max(1, Math.ceil(words / 200));
  }
}
