import { Component, HostListener, OnDestroy, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, RouterModule } from '@angular/router';
import { DomSanitizer, SafeResourceUrl } from '@angular/platform-browser';
import { ArticleApiError, ArticleApiService } from '../../../core/services/article-api.service';
import {
  ARTICLE_FILE_FORMAT_ORDER,
  ARTICLE_FILE_FORMAT_SPECS,
  ARTICLE_STATUS_BADGES,
  Article,
  ArticleFileFormat,
  ArticleFileFormatSpec,
  ArticleStatusBadge,
  articleFileId,
} from '../../../core/models/article.model';
import { AuthService } from '../../../services/auth.service';
import { IconComponent } from '../../../shared/icon/icon.component';

type LoadState = 'loading' | 'loaded' | 'not-found' | 'error';

type ViewerStatus = 'loading' | 'ready' | 'error';

/** Per-format viewer state, one per *attached* file slot. */
interface ViewerSlot {
  status: ViewerStatus;
  /** Raw object URL — kept so ngOnDestroy can revoke it. */
  objectUrl: string | null;
  /**
   * The same URL, sanitizer-approved. Only the PDF needs this: Angular treats
   * `iframe[src]` as a RESOURCE_URL and refuses to bind a raw string, whereas
   * `img[src]`/`video[src]` are plain URL context and take the string as-is.
   */
  safeUrl: SafeResourceUrl | null;
  error: string | null;
}

const emptyViewer = (): ViewerSlot => ({
  status: 'loading',
  objectUrl: null,
  safeUrl: null,
  error: null,
});

/** How many times the watermark text is tiled across the overlay. */
const WATERMARK_REPEATS = 28;

/**
 * Read-only detail view for a single workflow article (ArticleApiService),
 * including the native inline viewer for whichever of the three formats are
 * attached.
 *
 * Files are streamed through our own API as blobs and rendered from object
 * URLs — no Google Drive URL is ever built or exposed client-side (spec §10.2:
 * zéro téléchargement direct).
 *
 * No workflow actions here yet (submit/validate/reject land later).
 */
@Component({
  selector: 'app-article-workflow-detail',
  standalone: true,
  imports: [CommonModule, RouterModule, IconComponent],
  templateUrl: './article-detail.component.html',
  styleUrl: './article-detail.component.scss',
})
export class ArticleWorkflowDetailComponent implements OnInit, OnDestroy {
  private route = inject(ActivatedRoute);
  private api = inject(ArticleApiService);
  private auth = inject(AuthService);
  private sanitizer = inject(DomSanitizer);

  readonly formats = ARTICLE_FILE_FORMAT_ORDER;
  readonly watermarkRepeats = Array.from({ length: WATERMARK_REPEATS }, (_, i) => i);

  article = signal<Article | null>(null);
  state = signal<LoadState>('loading');
  errorMessage = signal<string | null>(null);

  viewers = signal<Record<ArticleFileFormat, ViewerSlot>>({
    pdf: emptyViewer(),
    infographie: emptyViewer(),
    video: emptyViewer(),
  });

  statusBadge = computed<ArticleStatusBadge | null>(() => {
    const a = this.article();
    return a ? ARTICLE_STATUS_BADGES[a.status] : null;
  });

  isGoldenRule = computed(() => this.article()?.criticite === 'golden_rule');

  /** Only the slots with a `format_*_drive_id` — nothing else is ever fetched. */
  attachedFormats = computed<ArticleFileFormat[]>(() => {
    const a = this.article();
    return a ? this.formats.filter(f => this.hasFormat(a, f)) : [];
  });

  /**
   * Identifies who opened the document, for the overlay. `matricule` is the
   * employee id the spec asks for; AuthService only started carrying it
   * recently, so a session cached before then (or one created by the local
   * register() path, which never had one) falls back to the email — still
   * uniquely identifying, which is the whole point of a watermark.
   */
  watermarkText = computed(() => {
    const user = this.auth.currentUser();
    const name = user?.name || 'Utilisateur inconnu';
    const employeeId = user?.matricule || user?.email || '—';
    return `${name} · ${employeeId} · ${this.viewedAt}`;
  });

  /** Captured once: this is when *this* user opened *this* document. */
  private readonly viewedAt = new Intl.DateTimeFormat('fr-FR', {
    dateStyle: 'short',
    timeStyle: 'short',
  }).format(new Date());

  /** Kept for retry(): `article()` is still null while state is 'error'. */
  private articleId: string | null = null;

  /**
   * Closes the obvious "save image/video as…" affordance across the page.
   * Explicitly NOT a security boundary — devtools, the network tab and the
   * blob URL itself all remain reachable. It exists because the spec asks for
   * it, in the same spirit as controlsList="nodownload" on the video.
   */
  @HostListener('contextmenu', ['$event'])
  onContextMenu(event: MouseEvent): void {
    event.preventDefault();
  }

  ngOnInit(): void {
    this.articleId = this.route.snapshot.paramMap.get('id');

    if (!this.articleId) {
      this.state.set('not-found');
      return;
    }

    this.load(this.articleId);
  }

  ngOnDestroy(): void {
    // Without this, navigating between several articles in one session leaks
    // every blob (a 100 MB video included) until a full page reload.
    this.revokeAllObjectUrls();
  }

  retry(): void {
    if (this.articleId) this.load(this.articleId);
  }

  load(id: string): void {
    this.state.set('loading');
    this.errorMessage.set(null);
    this.resetViewers();

    this.api.get(id).subscribe({
      next: article => {
        this.article.set(article);
        this.state.set('loaded');
        this.loadAttachedFiles(article);
      },
      error: (err: ArticleApiError) => {
        // A lecteur hitting a draft they can't see, or a genuinely wrong id,
        // both surface as a plain 404 from ArticleController::show() — that's
        // "not found" from this screen's point of view either way.
        if (err.status === 404) {
          this.state.set('not-found');
        } else {
          this.errorMessage.set(err.message);
          this.state.set('error');
        }
      },
    });
  }

  // ---------------------------------------------------------------- files

  private loadAttachedFiles(article: Article): void {
    for (const format of this.formats) {
      if (!this.hasFormat(article, format)) continue;
      this.loadFile(article.id, format);
    }
  }

  private loadFile(articleId: string, format: ArticleFileFormat): void {
    this.patchViewer(format, { status: 'loading', error: null });

    this.api.retrieveFile(articleId, format).subscribe({
      next: blob => {
        const objectUrl = URL.createObjectURL(blob);

        this.patchViewer(format, {
          status: 'ready',
          objectUrl,
          // Only the PDF is bound into an <iframe>; see ViewerSlot.safeUrl.
          // `#toolbar=0&navpanes=0` asks the browser's built-in PDF viewer to
          // drop its own toolbar, which is where its download/print buttons
          // live. Chromium/Edge honour it; a viewer that ignores it still
          // shows its native toolbar, so this is an affordance, not a control.
          safeUrl:
            format === 'pdf'
              ? this.sanitizer.bypassSecurityTrustResourceUrl(`${objectUrl}#toolbar=0&navpanes=0`)
              : null,
        });
      },
      error: (err: ArticleApiError) => {
        this.patchViewer(format, { status: 'error', error: err.message });
      },
    });
  }

  private patchViewer(format: ArticleFileFormat, patch: Partial<ViewerSlot>): void {
    this.viewers.update(v => ({ ...v, [format]: { ...v[format], ...patch } }));
  }

  private resetViewers(): void {
    this.revokeAllObjectUrls();
    this.viewers.set({
      pdf: emptyViewer(),
      infographie: emptyViewer(),
      video: emptyViewer(),
    });
  }

  private revokeAllObjectUrls(): void {
    const current = this.viewers();
    for (const format of this.formats) {
      const url = current[format].objectUrl;
      if (url) URL.revokeObjectURL(url);
    }
  }

  // --------------------------------------------------------------- lookup

  viewer(format: ArticleFileFormat): ViewerSlot {
    return this.viewers()[format];
  }

  spec(format: ArticleFileFormat): ArticleFileFormatSpec {
    return ARTICLE_FILE_FORMAT_SPECS[format];
  }

  hasFormat(article: Article, format: ArticleFileFormat): boolean {
    return articleFileId(article, format) !== null;
  }
}
