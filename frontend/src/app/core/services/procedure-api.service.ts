import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpErrorResponse, HttpEvent, HttpEventType } from '@angular/common/http';
import { Observable, throwError } from 'rxjs';
import { catchError, map } from 'rxjs/operators';
import { environment } from '../../../environments/environment';
import { FormatSpec, Procedure, ProcedurePayload, TriptychUploadResult } from '../models/procedure.model';

/**
 * Progress notification emitted while a triptych asset uploads.
 * `done` carries the server response; everything before it is progress only.
 */
export type UploadEvent =
  | { kind: 'progress'; percent: number; loaded: number; total: number }
  | { kind: 'done'; result: TriptychUploadResult };

/**
 * Live HTTP client for the procedures/triptych API.
 *
 * Deliberately separate from the legacy localStorage-backed `ProcedureService`
 * (src/app/services/procedure.service.ts), which still powers the demo list
 * page. Keeping them apart means wiring the triptych screens to the real Laravel
 * backend does not disturb the pages that still run offline.
 */
@Injectable({ providedIn: 'root' })
export class ProcedureApiService {
  private http = inject(HttpClient);
  private base = `${environment.apiUrl}/v1/procedures`;

  /** Turn a Laravel error envelope into a single readable French string. */
  private static toMessage(err: HttpErrorResponse, fallback: string): string {
    if (err.status === 0) {
      return 'Serveur injoignable. Vérifiez que l’API Laravel tourne sur ' + environment.apiUrl + '.';
    }
    if (err.status === 401) {
      return 'Session expirée ou non authentifiée. Reconnectez-vous.';
    }
    if (err.status === 403) {
      return 'Vous n’avez pas les droits requis (rôle admin ou process_owner).';
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
      throwError(() => new Error(ProcedureApiService.toMessage(err, fallback)));
  }

  list(): Observable<Procedure[]> {
    return this.http
      .get<Procedure[]>(this.base)
      .pipe(catchError(this.fail('Impossible de charger les procédures.')));
  }

  get(id: number): Observable<Procedure> {
    return this.http
      .get<Procedure>(`${this.base}/${id}`)
      .pipe(catchError(this.fail('Procédure introuvable.')));
  }

  create(payload: ProcedurePayload): Observable<Procedure> {
    return this.http
      .post<Procedure>(this.base, payload)
      .pipe(catchError(this.fail('Impossible de créer la procédure.')));
  }

  update(id: number, payload: Partial<ProcedurePayload>): Observable<Procedure> {
    return this.http
      .patch<Procedure>(`${this.base}/${id}`, payload)
      .pipe(catchError(this.fail('Impossible de mettre à jour la procédure.')));
  }

  remove(id: number): Observable<unknown> {
    return this.http
      .delete(`${this.base}/${id}`)
      .pipe(catchError(this.fail('Impossible de supprimer la procédure.')));
  }

  /**
   * Upload ONE triptych asset, streaming real byte-level progress.
   *
   * One request per slot rather than one combined request: each slot then gets
   * an independent progress bar and an independent failure, so a rejected 90 MB
   * video does not discard an already-uploaded PDF.
   *
   * `procedureId` binds the asset to an existing procedure server-side (used in
   * edit mode); omit it during creation and pass the returned path to create().
   */
  uploadAsset(spec: FormatSpec, file: File, procedureId?: number): Observable<UploadEvent> {
    const body = new FormData();
    body.append(spec.field, file);
    if (procedureId) {
      body.append('procedure_id', String(procedureId));
    }

    return this.http
      .post<TriptychUploadResult>(`${this.base}/upload-triptych`, body, {
        reportProgress: true,
        observe: 'events',
      })
      .pipe(
        map((event: HttpEvent<TriptychUploadResult>): UploadEvent | null => {
          if (event.type === HttpEventType.UploadProgress) {
            // `total` is absent on some proxies; fall back to the known file size.
            const total = event.total ?? file.size;
            return {
              kind: 'progress',
              loaded: event.loaded,
              total,
              // Hold at 99% until the server actually answers — showing 100%
              // while Laravel is still writing the file reads as a hang.
              percent: total > 0 ? Math.min(99, Math.round((event.loaded / total) * 100)) : 0,
            };
          }
          if (event.type === HttpEventType.Response) {
            return { kind: 'done', result: event.body as TriptychUploadResult };
          }
          return null;
        }),
        // Drop the event types we do not model (Sent, ResponseHeader…).
        filterNonNull(),
        catchError(this.fail(`Échec du téléversement de « ${file.name} ».`))
      );
  }
}

/** Narrowing helper: strip nulls and keep the emitted type non-nullable. */
function filterNonNull<T>() {
  return (source: Observable<T | null>): Observable<T> =>
    new Observable<T>(subscriber =>
      source.subscribe({
        next: value => {
          if (value !== null) subscriber.next(value);
        },
        error: err => subscriber.error(err),
        complete: () => subscriber.complete(),
      })
    );
}
