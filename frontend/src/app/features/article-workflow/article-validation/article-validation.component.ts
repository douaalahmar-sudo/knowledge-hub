import { Component, OnDestroy, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterModule } from '@angular/router';
import { Observable } from 'rxjs';
import { finalize } from 'rxjs/operators';
import { ArticleApiError, ArticleApiService } from '../../../core/services/article-api.service';
import {
  ARTICLE_STATUS_BADGES,
  Article,
  ArticleStatus,
  ArticleStatusBadge,
} from '../../../core/models/article.model';
import { AuthService } from '../../../services/auth.service';
import { IconComponent } from '../../../shared/icon/icon.component';

/**
 * The two statuses this queue is about. Everything else (draft, published,
 * archived) is filtered out client-side — see loadQueue().
 */
const PENDING_STATUSES: ArticleStatus[] = ['pending_metier', 'pending_qualite'];

/** How long a success confirmation stays on screen. */
const SUCCESS_TIMEOUT_MS = 6000;

/**
 * Validation queue for the article workflow: everything sitting in
 * pending_metier or pending_qualite, with the validate/reject actions the
 * current user is actually allowed to take on each row.
 *
 * Filtering is client-side on purpose. GET /v1/articles takes no status
 * parameter (ArticleController::index() applies RLS filiale scoping plus a
 * published-only restriction for lecteurs, and nothing else), so a
 * responsable_departement or qualite user receives every status their filiale
 * has. Narrowing to the two pending ones here needs no backend change; add a
 * `?status=` filter server-side only once the article count makes shipping the
 * full list expensive.
 *
 * Which actions show is driven by `access_role` (AuthService.hasAccessRole),
 * NOT by the legacy `roles`-table role behind canAccess() — the validate-metier
 * and validate-qualite Gates are both defined on access_role. Hiding a button
 * is a UI affordance only; ArticleController re-checks the Gate on every call.
 */
@Component({
  selector: 'app-article-workflow-validation',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterModule, IconComponent],
  templateUrl: './article-validation.component.html',
  styleUrl: './article-validation.component.scss',
})
export class ArticleWorkflowValidationComponent implements OnInit, OnDestroy {
  private api = inject(ArticleApiService);
  private auth = inject(AuthService);

  articles = signal<Article[]>([]);
  isLoading = signal(true);
  errorMessage = signal<string | null>(null);

  /** Confirmation banner after a successful validate/reject. */
  successMessage = signal<string | null>(null);
  /** Failure of an *action* — distinct from errorMessage(), which is the load. */
  actionError = signal<string | null>(null);

  /** Id of the row with an action in flight; disables that row's buttons. */
  busyId = signal<string | null>(null);
  /** Id of the row whose reject-reason box is open, if any. */
  rejectingId = signal<string | null>(null);
  rejectReason = '';

  private successTimer: ReturnType<typeof setTimeout> | null = null;

  /** pending_metier is the responsable_departement's step... */
  private canValidateMetier = computed(() =>
    this.auth.hasAccessRole(['responsable_departement'])
  );
  /** ...and pending_qualite the qualité team's. `admin` passes both. */
  private canValidateQualite = computed(() => this.auth.hasAccessRole(['qualite']));

  /** True when the user can act on at least one status — drives the subtitle. */
  isValidator = computed(() => this.canValidateMetier() || this.canValidateQualite());

  ngOnInit(): void {
    this.loadQueue();
  }

  ngOnDestroy(): void {
    this.clearSuccessTimer();
  }

  loadQueue(): void {
    this.isLoading.set(true);
    this.errorMessage.set(null);
    this.actionError.set(null);
    this.closeReject();

    this.api
      .list()
      .pipe(finalize(() => this.isLoading.set(false)))
      .subscribe({
        next: articles =>
          this.articles.set(articles.filter(a => PENDING_STATUSES.includes(a.status))),
        // ArticleApiService already maps this into a ready-to-display French
        // message — nothing left to unwrap here.
        error: (err: ArticleApiError) => this.errorMessage.set(err.message),
      });
  }

  // -------------------------------------------------------------- actions

  /**
   * Whether *this* user may act on *this* article, given where it sits in the
   * workflow. Any other combination (wrong stage for your role, or no
   * validation role at all) renders the row with no actions.
   */
  canAct(article: Article): boolean {
    return article.status === 'pending_metier'
      ? this.canValidateMetier()
      : this.canValidateQualite();
  }

  /** Routes to the endpoint matching the article's current stage. */
  validate(article: Article): void {
    const call =
      article.status === 'pending_metier'
        ? this.api.validateMetier(article.id)
        : this.api.validateQualite(article.id);

    const done =
      article.status === 'pending_metier'
        ? 'transmis à la validation qualité'
        : 'publié';

    this.run(article, call, `« ${article.title} » ${done}.`);
  }

  openReject(article: Article): void {
    this.rejectingId.set(article.id);
    this.rejectReason = '';
    this.actionError.set(null);
  }

  closeReject(): void {
    this.rejectingId.set(null);
    this.rejectReason = '';
  }

  /**
   * The reason is mandatory server-side (RejectArticleRequest: required|string)
   * and is what the author gets told, so an empty one is refused here rather
   * than sent off to come back as a 422.
   */
  confirmReject(article: Article): void {
    const reason = this.rejectReason.trim();

    if (!reason) {
      this.actionError.set('Indiquez un motif de rejet — il est transmis à l’auteur.');
      return;
    }

    this.run(
      article,
      this.api.reject(article.id, { reason }),
      `« ${article.title} » renvoyé en brouillon à ${article.author.name}.`
    );
  }

  /**
   * Shared tail for all three actions: run it, drop the row from the queue on
   * success (it has left both pending states either way, so it no longer
   * belongs here), and surface a confirmation.
   */
  private run(article: Article, call: Observable<Article>, success: string): void {
    this.busyId.set(article.id);
    this.actionError.set(null);
    this.successMessage.set(null);

    call.pipe(finalize(() => this.busyId.set(null))).subscribe({
      next: () => {
        this.articles.update(list => list.filter(a => a.id !== article.id));
        this.closeReject();
        this.showSuccess(success);
      },
      error: (err: ArticleApiError) => this.actionError.set(err.message),
    });
  }

  private showSuccess(message: string): void {
    this.clearSuccessTimer();
    this.successMessage.set(message);
    this.successTimer = setTimeout(() => this.successMessage.set(null), SUCCESS_TIMEOUT_MS);
  }

  private clearSuccessTimer(): void {
    if (this.successTimer) clearTimeout(this.successTimer);
    this.successTimer = null;
  }

  // --------------------------------------------------------------- lookup

  statusBadge(status: ArticleStatus): ArticleStatusBadge {
    return ARTICLE_STATUS_BADGES[status];
  }

  isGoldenRule(article: Article): boolean {
    return article.criticite === 'golden_rule';
  }

  /** Label for the validate button — the two stages do different things. */
  validateLabel(article: Article): string {
    return article.status === 'pending_metier' ? 'Valider (métier)' : 'Valider (qualité)';
  }
}
