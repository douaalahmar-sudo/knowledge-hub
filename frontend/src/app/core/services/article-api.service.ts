import { inject, Injectable } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { environment } from '../../../environments/environment';
import {
  Article,
  ArticleFileFormat,
  CreateArticlePayload,
  RejectArticlePayload,
  UpdateArticlePayload,
} from '../models/article.model';

/**
 * Live HTTP client for the articles API
 * (backend/app/Http/Controllers/ArticleController.php).
 *
 * Deliberately separate from the legacy localStorage-backed `ArticleService`
 * (src/app/core/services/article.service.ts), which still powers the demo
 * knowledge-base pages built against the old schema — same split as
 * ProcedureApiService vs ProcedureService on the procedures side.
 *
 * Auth is handled globally by src/app/interceptors/auth.interceptor.ts, so
 * nothing here touches tokens or headers.
 */
@Injectable({ providedIn: 'root' })
export class ArticleApiService {
  private http = inject(HttpClient);
  private base = `${environment.apiUrl}/v1/articles`;

  /**
   * Turn a Laravel error envelope into a single readable French string.
   *
   * Same status-code branching as ProcedureApiService, with one deliberate
   * difference at 403: most article endpoints already return a specific,
   * accurate French reason (UpdateArticleRequest, RejectArticleRequest,
   * UploadArticleFileRequest, ArticleController::destroy() all set their own
   * `message`) — hardcoding one string the way ProcedureApiService does would
   * throw those away. The three plain Gate denials that used to lack one
   * (create-articles, validate-metier, validate-qualite) now return
   * Response::deny('...') server-side specifically so this branch never needs
   * to fall back to Laravel's generic English default in practice.
   */
  private static toMessage(err: HttpErrorResponse, fallback: string): string {
    if (err.status === 0) {
      return 'Serveur injoignable. Vérifiez que l’API Laravel tourne sur ' + environment.apiUrl + '.';
    }
    if (err.status === 401) {
      return 'Session expirée ou non authentifiée. Reconnectez-vous.';
    }
    if (err.status === 403) {
      return err.error?.message || 'Vous n’avez pas les droits requis pour effectuer cette action sur cet article.';
    }
    if (err.status === 413) {
      return 'Fichier refusé par le serveur (limite PHP upload_max_filesize dépassée).';
    }

    // 422 — surface the first field error, which is the actionable one.
    const errors = err.error?.errors as Record<string, string[]> | undefined;
    if (errors) {
      const first = Object.values(errors)[0];
      if (first?.length) return first[0];
    }

    return err.error?.message || fallback;
  }

  private fail(fallback: string) {
    return (err: HttpErrorResponse) =>
      throwError(() => new Error(ArticleApiService.toMessage(err, fallback)));
  }

  /** Visibility (lecteur sees published+active only) is enforced server-side. */
  list(): Observable<Article[]> {
    return this.http
      .get<Article[]>(this.base)
      .pipe(catchError(this.fail('Impossible de charger les articles.')));
  }

  get(id: string): Observable<Article> {
    return this.http
      .get<Article>(`${this.base}/${id}`)
      .pipe(catchError(this.fail('Article introuvable.')));
  }

  /** Always created as a draft, owned by the caller — see StoreArticleRequest. */
  create(payload: CreateArticlePayload): Observable<Article> {
    return this.http
      .post<Article>(this.base, payload)
      .pipe(catchError(this.fail('Impossible de créer l’article.')));
  }

  /** Author-and-draft-only; the server 403s otherwise. */
  update(id: string, payload: UpdateArticlePayload): Observable<Article> {
    return this.http
      .put<Article>(`${this.base}/${id}`, payload)
      .pipe(catchError(this.fail('Impossible de mettre à jour l’article.')));
  }

  /** draft -> pending_metier. No body — `null` signals that explicitly. */
  submit(id: string): Observable<Article> {
    return this.http
      .post<Article>(`${this.base}/${id}/submit`, null)
      .pipe(catchError(this.fail('Impossible de soumettre l’article pour validation.')));
  }

  /** pending_metier -> pending_qualite. */
  validateMetier(id: string): Observable<Article> {
    return this.http
      .post<Article>(`${this.base}/${id}/validate-metier`, null)
      .pipe(catchError(this.fail('Impossible de valider l’article (étape métier).')));
  }

  /** pending_qualite -> published. */
  validateQualite(id: string): Observable<Article> {
    return this.http
      .post<Article>(`${this.base}/${id}/validate-qualite`, null)
      .pipe(catchError(this.fail('Impossible de valider l’article (étape qualité).')));
  }

  /** pending_metier or pending_qualite -> draft. */
  reject(id: string, payload: RejectArticlePayload): Observable<Article> {
    return this.http
      .post<Article>(`${this.base}/${id}/reject`, payload)
      .pipe(catchError(this.fail('Impossible de rejeter l’article.')));
  }

  /**
   * Overwrites whatever was already in that slot. Field name must be `file` —
   * that's what the server reads via $request->file('file').
   */
  uploadFile(id: string, format: ArticleFileFormat, file: File): Observable<Article> {
    const body = new FormData();
    body.append('file', file);

    return this.http
      .post<Article>(`${this.base}/${id}/files/${format}`, body)
      .pipe(catchError(this.fail(`Échec du téléversement de « ${file.name} ».`)));
  }

  /**
   * Content-Type is whatever the server set (read from Drive's own stored
   * metadata) — the blob carries it, bind it via a Blob URL to render inline.
   */
  retrieveFile(id: string, format: ArticleFileFormat): Observable<Blob> {
    return this.http
      .get(`${this.base}/${id}/files/${format}`, { responseType: 'blob' })
      .pipe(catchError(this.fail('Impossible de récupérer le fichier.')));
  }
}
