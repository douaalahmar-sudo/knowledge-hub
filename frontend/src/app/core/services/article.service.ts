import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';

export interface Article {
  id?: string;
  title: string;
  slug?: string;
  summary?: string;
  content: string;
  category: 'news_announcements' | 'onboarding_guides' | 'policies_guidelines' | 'hr_documentation';
  tags?: string[];
  status: 'draft' | 'published' | 'archived';
  published_at?: string;
  tenant_id?: string;
  author_id?: number;
  author?: { id: number; name: string; email: string };
  cover_image_url?: string;
  attachments?: { name: string; url: string; size: number }[];
  reading_time_minutes?: number;
  created_at?: string;
}

@Injectable({
  providedIn: 'root'
})
export class ArticleService {
  private apiUrl = `${environment.apiUrl}/v1/articles`;

  constructor(private http: HttpClient) {}

  getArticles(params?: { category?: string; search?: string; page?: number }): Observable<any> {
    let httpParams = new HttpParams();
    if (params?.category) httpParams = httpParams.set('category', params.category);
    if (params?.search) httpParams = httpParams.set('search', params.search);
    if (params?.page) httpParams = httpParams.set('page', params.page.toString());

    return this.http.get<any>(this.apiUrl, { params: httpParams });
  }

  getArticleBySlug(slug: string): Observable<Article> {
    return this.http.get<Article>(`${this.apiUrl}/${slug}`);
  }

  createArticle(article: Partial<Article>): Observable<Article> {
    return this.http.post<Article>(this.apiUrl, article);
  }

  updateArticle(id: string, article: Partial<Article>): Observable<Article> {
    return this.http.put<Article>(`${this.apiUrl}/${id}`, article);
  }

  deleteArticle(id: string): Observable<any> {
    return this.http.delete(`${this.apiUrl}/${id}`);
  }
}