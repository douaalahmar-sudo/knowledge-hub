import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';

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

/** Fields collected from the editor form before turning them into FormData. */
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
  private http = inject(HttpClient);
  // Auth token is attached automatically by the authInterceptor.
  private apiUrl = `${environment.apiUrl}/v1/articles`;

  // 1. List articles with optional category / search / status filters.
  getArticles(params: ArticleQuery = {}): Observable<any> {
    let httpParams = new HttpParams();
    if (params.category) httpParams = httpParams.set('category', params.category);
    if (params.search) httpParams = httpParams.set('search', params.search);
    if (params.status) httpParams = httpParams.set('status', params.status);
    if (params.page) httpParams = httpParams.set('page', String(params.page));

    return this.http.get<any>(this.apiUrl, { params: httpParams });
  }

  // 2. Read a single article by slug.
  getArticleBySlug(slug: string): Observable<Article> {
    return this.http.get<Article>(`${this.apiUrl}/${slug}`);
  }

  // 3. Create a new article (multipart — supports cover image + attachments).
  createArticle(payload: ArticlePayload): Observable<Article> {
    const formData = this.buildFormData(payload);
    return this.http.post<Article>(this.apiUrl, formData);
  }

  // 4. Update an existing article.
  //    PHP does not parse multipart bodies on PUT, so we POST with a _method override.
  updateArticle(idOrSlug: string, payload: ArticlePayload): Observable<Article> {
    const formData = this.buildFormData(payload);
    formData.append('_method', 'PUT');
    return this.http.post<Article>(`${this.apiUrl}/${idOrSlug}`, formData);
  }

  // 5. Archive an article (soft state change in the publishing workflow).
  archiveArticle(idOrSlug: string): Observable<Article> {
    const formData = new FormData();
    formData.append('_method', 'PUT');
    formData.append('status', 'archived');
    return this.http.post<Article>(`${this.apiUrl}/${idOrSlug}`, formData);
  }

  /**
   * Translate a form payload into multipart FormData that Laravel can consume.
   * - `status` drives `published_at`: set to now when publishing, cleared otherwise.
   * - Files are only appended when actually provided.
   */
  private buildFormData(payload: ArticlePayload): FormData {
    const fd = new FormData();
    fd.append('title', payload.title ?? '');
    fd.append('category', payload.category ?? '');
    fd.append('summary', payload.summary ?? '');
    fd.append('content', payload.content ?? '');
    fd.append('status', payload.status ?? 'draft');

    // published_at timestamp only when the article is being published.
    if (payload.status === 'published') {
      fd.append('published_at', new Date().toISOString());
    }

    // Tags as a repeated field: tags[]
    (payload.tags ?? []).forEach(tag => fd.append('tags[]', tag));

    // Cover: prefer an uploaded file, fall back to a pasted URL.
    if (payload.coverImageFile) {
      fd.append('cover_image', payload.coverImageFile);
    } else if (payload.cover_image_url) {
      fd.append('cover_image_url', payload.cover_image_url);
    }

    // Attachments (PDFs, docs...) as a repeated file field: attachments[]
    (payload.attachmentFiles ?? []).forEach(file => fd.append('attachments[]', file));

    return fd;
  }

  /** Client-side reading-time estimate (words / 200 WPM), HTML stripped. */
  estimateReadingTime(content: string | undefined | null): number {
    const text = (content ?? '').replace(/<[^>]*>/g, ' ');
    const words = text.trim().split(/\s+/).filter(Boolean).length;
    return Math.max(1, Math.ceil(words / 200));
  }
}
