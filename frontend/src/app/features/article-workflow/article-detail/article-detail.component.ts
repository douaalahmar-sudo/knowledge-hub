import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, RouterModule } from '@angular/router';
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
import { IconComponent } from '../../../shared/icon/icon.component';

type LoadState = 'loading' | 'loaded' | 'not-found' | 'error';

/**
 * Read-only detail view for a single workflow article (ArticleApiService).
 *
 * Same features/article-workflow/ location and naming pattern as
 * ArticleWorkflowListComponent/ArticleWorkflowEditorComponent, for the same
 * reason: features/articles/ holds the legacy mock-backed reader for the old
 * category/tags schema, a genuinely different feature.
 *
 * Layout + data + file-presence indicators only, by design — no viewer (that
 * needs the watermark work first) and no workflow actions (submit/validate/
 * reject land once this layout is solid).
 */
@Component({
  selector: 'app-article-workflow-detail',
  standalone: true,
  imports: [CommonModule, RouterModule, IconComponent],
  templateUrl: './article-detail.component.html',
  styleUrl: './article-detail.component.scss',
})
export class ArticleWorkflowDetailComponent implements OnInit {
  private route = inject(ActivatedRoute);
  private api = inject(ArticleApiService);

  readonly formats = ARTICLE_FILE_FORMAT_ORDER;

  article = signal<Article | null>(null);
  state = signal<LoadState>('loading');
  errorMessage = signal<string | null>(null);

  statusBadge = computed<ArticleStatusBadge | null>(() => {
    const a = this.article();
    return a ? ARTICLE_STATUS_BADGES[a.status] : null;
  });

  isGoldenRule = computed(() => this.article()?.criticite === 'golden_rule');

  /** Kept for retry(): the article signal is still null while state is
   *  'error', so retry can't recover the id from it the way it can once
   *  something has actually loaded. */
  private articleId: string | null = null;

  ngOnInit(): void {
    this.articleId = this.route.snapshot.paramMap.get('id');

    if (!this.articleId) {
      this.state.set('not-found');
      return;
    }

    this.load(this.articleId);
  }

  retry(): void {
    if (this.articleId) this.load(this.articleId);
  }

  load(id: string): void {
    this.state.set('loading');
    this.errorMessage.set(null);

    this.api.get(id).subscribe({
      next: article => {
        this.article.set(article);
        this.state.set('loaded');
      },
      error: (err: ArticleApiError) => {
        // A lecteur hitting a draft they can't see, or a genuinely wrong id,
        // both surface as a plain 404 from ArticleController::show() — that's
        // "not found" from this screen's point of view either way, not an
        // error to report or retry.
        if (err.status === 404) {
          this.state.set('not-found');
        } else {
          this.errorMessage.set(err.message);
          this.state.set('error');
        }
      },
    });
  }

  spec(format: ArticleFileFormat): ArticleFileFormatSpec {
    return ARTICLE_FILE_FORMAT_SPECS[format];
  }

  hasFormat(article: Article, format: ArticleFileFormat): boolean {
    return articleFileId(article, format) !== null;
  }
}
