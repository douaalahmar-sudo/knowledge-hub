import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { finalize } from 'rxjs/operators';
import { ArticleApiService } from '../../../core/services/article-api.service';
import { Article, ArticleCriticite, ArticleStatus } from '../../../core/models/article.model';
import { IconComponent } from '../../../shared/icon/icon.component';

interface StatusBadge {
  label: string;
  background: string;
  color: string;
}

/** draft=gray, pending_*=amber, published=green, archived=neutral (darker than draft, on purpose). */
const STATUS_BADGES: Record<ArticleStatus, StatusBadge> = {
  draft: { label: 'Brouillon', background: '#f1f5f9', color: '#475569' },
  pending_metier: { label: 'En attente — métier', background: '#fef3c7', color: '#b45309' },
  pending_qualite: { label: 'En attente — qualité', background: '#fef3c7', color: '#b45309' },
  published: { label: 'Publié', background: '#dcfce7', color: '#15803d' },
  archived: { label: 'Archivé', background: '#e2e8f0', color: '#334155' },
};

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

  ngOnInit(): void {
    this.loadArticles();
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

  statusBadge(status: ArticleStatus): StatusBadge {
    return STATUS_BADGES[status];
  }

  isGoldenRule(criticite: ArticleCriticite): boolean {
    return criticite === 'golden_rule';
  }
}
