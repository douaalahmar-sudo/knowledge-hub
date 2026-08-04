import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { environment } from '../../../environments/environment';

/** A server-issued grant to print one article, once, for a few minutes. */
export interface PrintGrant {
  id: string;
  article_id: string;
  expires_at: string;
  /** The "ID MATRICULE COMPTABLE" the §11.1 banner prints. */
  matricule: string;
  holder_name: string;
}

/**
 * Holds the §11.1 print grant for the current tab.
 *
 * §11 disables printing across the whole Hub (see the `@media print` block in
 * styles.scss). This service is the only thing that lifts that, and only while
 * a live grant is held: it stamps `data-print-authorized` on <html>, which is
 * what the authorized-print CSS keys off.
 *
 * ## Deliberately in memory only
 *
 * The grant is never written to localStorage or sessionStorage. A grant that
 * survives a reload is a standing permission with extra steps, and the point of
 * the per-print model is that it is not one. Closing the tab ends it.
 *
 * ## What this cannot enforce
 *
 * Nothing here stops a determined user with devtools from setting the attribute
 * by hand and printing — the same honest limit BlockCopyShortcutsDirective
 * documents. What the server-side grant gives is the record: an authorized
 * print is on the audit trail with its matricule, and a forged one is a print
 * of a page whose banner is not backed by any row. The client half is the
 * mechanism; the trail is the control.
 */
@Injectable({ providedIn: 'root' })
export class PrintAuthorizationService {
  private http = inject(HttpClient);
  private base = `${environment.apiUrl}/v1`;

  /** The live grant, or null. Cleared on expiry, on use, and on navigation away. */
  private grant = signal<PrintGrant | null>(null);

  readonly current = this.grant.asReadonly();

  readonly isAuthorized = computed(() => this.grant() !== null);

  /** Set when a grant is live so the CSS can reveal the print sheet. */
  private static readonly ATTRIBUTE = 'data-print-authorized';

  private expiryTimer: ReturnType<typeof setTimeout> | null = null;

  /**
   * Ask the server to authorize one print of $articleId.
   *
   * 403 (not admin/data_owner) and 422 (not the published current version) are
   * left to the caller: both carry an accurate French message from the API, and
   * swallowing them here would leave the user with a print button that silently
   * does nothing.
   */
  authorize(articleId: string): Observable<PrintGrant> {
    return this.http
      .post<PrintGrant>(`${this.base}/articles/${articleId}/print-authorizations`, null)
      .pipe(tap(grant => this.hold(grant)));
  }

  /**
   * Mark the grant used and open the browser's print dialogue.
   *
   * The dialogue opens whether or not the consume call succeeds. Refusing to
   * print because the *record* of the print failed would be the wrong way
   * round: the user is authorized, and a missing trail entry is a monitoring
   * problem, not a reason to deny an authorized action — the same trade
   * AuditLogger makes server-side.
   */
  print(): void {
    const grant = this.grant();

    if (!grant) return;

    this.http
      .post(`${this.base}/print-authorizations/${grant.id}/consume`, null)
      .subscribe({
        next: () => this.openDialogue(),
        error: () => this.openDialogue(),
      });
  }

  /** Ends the authorization — called on expiry, after printing, and on leaving. */
  release(): void {
    if (this.expiryTimer) {
      clearTimeout(this.expiryTimer);
      this.expiryTimer = null;
    }

    this.grant.set(null);
    document.documentElement.removeAttribute(PrintAuthorizationService.ATTRIBUTE);
  }

  private hold(grant: PrintGrant): void {
    this.release();

    this.grant.set(grant);
    document.documentElement.setAttribute(PrintAuthorizationService.ATTRIBUTE, 'true');

    // The server's expiry is authoritative — this timer only keeps the UI
    // honest, so a stale banner cannot sit on screen looking printable after
    // the grant it describes has died. A consume() past the deadline is
    // refused server-side regardless of what this timer did.
    const remaining = new Date(grant.expires_at).getTime() - Date.now();

    if (remaining > 0) {
      this.expiryTimer = setTimeout(() => this.release(), remaining);
    } else {
      this.release();
    }
  }

  /**
   * The grant is single-use, so the authorization ends with the dialogue —
   * whether the user prints or cancels. A second copy needs a second grant,
   * which is what makes "COPIE TRACÉE" mean one traced copy.
   */
  private openDialogue(): void {
    try {
      window.print();
    } finally {
      this.release();
    }
  }
}
