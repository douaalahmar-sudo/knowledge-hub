import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { AuthService } from '../../services/auth.service';

/** How many times the identity string is tiled across the overlay. */
const WATERMARK_REPEATS = 28;

/**
 * The traceability overlay required by cahier des charges §10.3, for any
 * on-screen consultation of a document.
 *
 * Drop it inside a positioned, overflow-hidden frame that wraps the document
 * itself:
 *
 *   <div class="frame" appBlockContextMenu>
 *     <iframe ...></iframe>
 *     <app-document-watermark />
 *   </div>
 *
 * Extracted from ArticleWorkflowDetailComponent, which was the only viewer in
 * the project carrying it, so procedures (and any later viewer) get the same
 * overlay rather than a second implementation that drifts from this one.
 *
 * It takes no inputs: one translucent grey covers dark, light and photographic
 * frames alike, so no call site has to describe what it is being laid over. See
 * the colour note in the SCSS.
 *
 * It is an identification measure, not a copy-protection one: anything drawn in
 * a browser can still be screenshotted. The point is that a leaked screenshot
 * carries the reader's identity.
 */
@Component({
  selector: 'app-document-watermark',
  standalone: true,
  template: `
    <!-- aria-hidden: this is a traceability artefact, not content. Repeating a
         44-character identity string 28 times to a screen reader would bury the
         document it is laid over. -->
    <div class="dw" aria-hidden="true">
      @for (i of repeats; track i) {
        <span class="dw__line">{{ text() }}</span>
      }
    </div>
  `,
  styleUrl: './document-watermark.component.scss',
})
export class DocumentWatermarkComponent implements OnInit {
  private auth = inject(AuthService);

  readonly repeats = Array.from({ length: WATERMARK_REPEATS }, (_, i) => i);

  /**
   * The API's view of where this client is connecting from. Null until it
   * lands, and permanently null if the call fails — see the fallback in text().
   */
  private clientIp = signal<string | null>(null);

  /**
   * Spec §10.3 fixes both the fields and their order:
   *   [Nom complet] | [Matricule] | [Adresse IP] | [Horodatage à la seconde]
   *
   * Every field degrades to a placeholder rather than rendering `undefined`,
   * since a watermark that says "undefined" is worse than one that admits a
   * gap. `matricule` is the employee id the spec asks for; a session cached
   * before AuthService started carrying it (or one created by the local
   * register() path, which never had one) falls back to the email — still
   * uniquely identifying, which is the whole point. The IP has no such second
   * source, so it degrades straight to the placeholder.
   */
  text = computed(() => {
    const user = this.auth.currentUser();
    const name = user?.name || 'Utilisateur inconnu';
    const employeeId = user?.matricule || user?.email || '—';
    const ip = this.clientIp() || '—';
    return `${name} | ${employeeId} | ${ip} | ${this.viewedAt}`;
  });

  /**
   * Captured once, at construction: this is when *this* user opened *this*
   * document. `timeStyle: 'medium'` is what makes fr-FR emit HH:MM:SS — the
   * spec asks for an "horodatage à la seconde", and 'short' drops the seconds.
   */
  private readonly viewedAt = new Intl.DateTimeFormat('fr-FR', {
    dateStyle: 'short',
    timeStyle: 'medium',
  }).format(new Date());

  ngOnInit(): void {
    // Not awaited: the overlay renders immediately with the placeholder and
    // swaps the address in when it arrives. Blocking a document on one field of
    // an overlay would trade a viewer that opens for a viewer that stalls.
    // clientIpOnce() is memoised, so several overlays on one page share a
    // single request.
    this.auth.clientIpOnce().subscribe(ip => this.clientIp.set(ip));
  }
}
