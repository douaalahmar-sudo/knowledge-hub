import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { finalize } from 'rxjs/operators';
import { ArticleApiService } from '../../../core/services/article-api.service';
import {
  ARTICLE_STATUS_BADGES,
  ARTICLE_STATUS_ORDER,
  Article,
  ArticleCriticite,
  ArticleStatus,
  ArticleStatusBadge,
} from '../../../core/models/article.model';
import { IconComponent } from '../../../shared/icon/icon.component';

/** Filter values, i.e. the domain values plus an "any" option. */
type StatusFilter = ArticleStatus | 'all';
type CriticiteFilter = ArticleCriticite | 'all';

/**
 * Case- AND accent-insensitive, so searching "procedure" matches "Procédure".
 * Article titles here are French and routinely accented, which makes an exact
 * substring match frustrating to type against.
 */
function normalize(value: string): string {
  return value
    .normalize('NFD')
    // \p{Mn} = Unicode nonspacing marks, exactly what NFD decomposition
    // splits an accented letter into ("é" -> "e" + U+0301).
    .replace(/\p{Mn}/gu, '')
    .toLowerCase();
}

/**
 * Read-only list for the real, workflow-backed articles (ArticleApiService).
 *
 * Deliberately a new location, not features/articles/article-list — that one
 * is the legacy category-browsing knowledge-base UI wired to the mock
 * ArticleService and the old category/tags/content schema. This is a
 * genuinely different feature (draft/pending_metier/pending_qualite/
 * published/archived workflow), same split ArticleApiService vs
 * ArticleService already documents, carried through to the component layer
 * and its class name to avoid any symbol collision.
 *
 * Display only, by design — no create/edit/delete actions here.
 */
@Component({
  selector: 'app-article-workflow-list',
  standalone: true,
  imports: [CommonModule, RouterModule, IconComponent],
  templateUrl: './article-list.component.html',
  styleUrl: './article-list.component.scss',
})
export class ArticleWorkflowListComponent implements OnInit {
  private articleApi = inject(ArticleApiService);

  articles = signal<Article[]>([]);
  isLoading = signal(true);
  errorMessage = signal<string | null>(null);

  // ------------------------------------------------------------- filtering
  // All client-side, and no new assumption: ArticleController::index() ends
  // in `$query->get()` with no pagination, so the whole visible set is
  // already in memory by the time this renders. Filtering server-side would
  // only start paying off once that endpoint paginates.

  searchTerm = signal('');
  statusFilter = signal<StatusFilter>('all');
  criticiteFilter = signal<CriticiteFilter>('all');

  readonly statusOptions = ARTICLE_STATUS_ORDER;
  readonly criticiteOptions: { value: CriticiteFilter; label: string }[] = [
    { value: 'all', label: 'Toutes' },
    { value: 'golden_rule', label: "Règle d'or" },
    { value: 'note', label: 'Note' },
  ];

  /**
   * Whether `term` (already normalized, already non-empty) hits any of the
   * searchable fields — spec §5.1 asks for keyword search across metadata, not
   * titles alone.
   *
   * OR *within* the search box, which is what makes one query box usable: the
   * user types "sécurité" without having to say which field to look in. The
   * AND against the status/criticite chips lives in filteredArticles and is
   * unaffected.
   *
   * `slug` is here rather than `id` deliberately. §5.1 lists ID as a mandatory
   * searchable field, but `id` is a UUID nobody reads off a screen and types
   * back in; `slug` is the human-usable identifier for the same row and is
   * derived from the title server-side. Note this means a slug match is often
   * also a title match — no harm, `some` short-circuits.
   *
   * Everything goes through normalize() so accents and case behave identically
   * on every field; a second comparison style here would mean "procedure"
   * finding an accented title but not an accented tag.
   */
  private matchesSearch(article: Article, term: string): boolean {
    // content_summary is nullable on the model, tags_metier is an array that
    // is routinely empty — neither can be fed to normalize() unguarded.
    const haystack: string[] = [
      article.title,
      article.slug,
      article.content_summary ?? '',
      ...article.tags_metier,
    ];

    return haystack.some(field => normalize(field).includes(term));
  }

  /** The three filters combined with AND. */
  filteredArticles = computed<Article[]>(() => {
    const term = normalize(this.searchTerm().trim());
    const status = this.statusFilter();
    const criticite = this.criticiteFilter();

    return this.articles().filter(
      article =>
        (status === 'all' || article.status === status) &&
        (criticite === 'all' || article.criticite === criticite) &&
        (term === '' || this.matchesSearch(article, term))
    );
  });

  /**
   * Drives the "no match" empty state and the reset button. Distinct from
   * "there are no articles at all", which is `articles().length === 0` — the
   * two need different messages, since one is a filter the user can clear and
   * the other isn't.
   */
  hasActiveFilters = computed(
    () =>
      this.searchTerm().trim() !== '' ||
      this.statusFilter() !== 'all' ||
      this.criticiteFilter() !== 'all'
  );

  ngOnInit(): void {
    this.loadArticles();
  }

  resetFilters(): void {
    this.searchTerm.set('');
    this.statusFilter.set('all');
    this.criticiteFilter.set('all');
  }

  loadArticles(): void {
    this.isLoading.set(true);
    this.errorMessage.set(null);

    // `finalize` guarantees the spinner clears on success, empty list, or error.
    this.articleApi
      .list()
      .pipe(finalize(() => this.isLoading.set(false)))
      .subscribe({
        next: (articles) => this.articles.set(articles),
        // ArticleApiService already maps this into a plain Error with a
        // ready-to-display French message — nothing left to unwrap here.
        error: (err: Error) => this.errorMessage.set(err.message),
      });
  }

  statusBadge(status: ArticleStatus): ArticleStatusBadge {
    return ARTICLE_STATUS_BADGES[status];
  }

  isGoldenRule(criticite: ArticleCriticite): boolean {
    return criticite === 'golden_rule';
  }
}
