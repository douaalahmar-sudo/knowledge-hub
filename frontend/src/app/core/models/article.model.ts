/**
 * Article domain types — mirrors the Laravel `articles` table and its
 * workflow enums exactly (backend/app/Models/Article.php,
 * backend/app/Enums/ArticleStatus.php, ArticleCriticite.php).
 *
 * Separate from the `Article`/`ArticleStatus` types already declared inline in
 * core/services/article.service.ts: that service is the legacy
 * localStorage-backed one built against the old category/tags/content schema
 * and still powers the existing demo knowledge-base pages. This file describes
 * the real, current backend schema — see ArticleApiService for the client that
 * actually talks to it.
 */

export type ArticleStatus =
  | 'draft'
  | 'pending_metier'
  | 'pending_qualite'
  | 'published'
  | 'archived';

export type ArticleCriticite = 'golden_rule' | 'note';

/** The three Drive-backed file slots (format_*_drive_id below). */
export type ArticleFileFormat = 'pdf' | 'infographie' | 'video';

/** Every article endpoint response eager-loads this — see ArticleController. */
export interface ArticleAuthorSummary {
  id: number;
  name: string;
  email: string;
}

export interface Article {
  id: string;
  filiale_id: string;
  title: string;
  slug: string;
  content_summary: string | null;
  tags_metier: string[];
  criticite: ArticleCriticite;
  status: ArticleStatus;

  format_pdf_drive_id: string | null;
  format_infographie_drive_id: string | null;
  format_video_drive_id: string | null;

  version: number;
  is_active_version: boolean;
  parent_article_id: string | null;

  author_id: number;
  validated_by_metier_id: number | null;
  validated_by_qualite_id: number | null;
  data_owner_id: number;

  published_at: string | null;
  created_at: string;
  updated_at: string;

  /** Always present — every endpoint response loads author:id,name,email. */
  author: ArticleAuthorSummary;
}

/**
 * Body accepted by POST /v1/articles. Everything else (status, filiale_id,
 * author_id, data_owner_id, slug) is set server-side — see StoreArticleRequest.
 */
export interface CreateArticlePayload {
  title: string;
  content_summary?: string | null;
  tags_metier?: string[];
  criticite?: ArticleCriticite;
}

/**
 * Body accepted by PUT /v1/articles/{id}. Same editable fields as create, all
 * optional (partial update) — see UpdateArticleRequest. Only ever accepted
 * while the article is still a draft and you're its author.
 */
export interface UpdateArticlePayload {
  title?: string;
  content_summary?: string | null;
  tags_metier?: string[];
  criticite?: ArticleCriticite;
}

/** Body accepted by POST /v1/articles/{id}/reject. */
export interface RejectArticlePayload {
  reason: string;
}
