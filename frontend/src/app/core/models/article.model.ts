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

// --------------------------------------------------------------------------
// Status badge styling — shared by ArticleWorkflowListComponent and
// ArticleWorkflowDetailComponent so both render the exact same colors/labels
// for a given status instead of two components independently guessing.
// --------------------------------------------------------------------------

export interface ArticleStatusBadge {
  label: string;
  background: string;
  color: string;
}

/** draft=gray, pending_*=amber, published=green, archived=neutral (darker than draft, on purpose). */
export const ARTICLE_STATUS_BADGES: Record<ArticleStatus, ArticleStatusBadge> = {
  draft: { label: 'Brouillon', background: '#f1f5f9', color: '#475569' },
  pending_metier: { label: 'En attente — métier', background: '#fef3c7', color: '#b45309' },
  pending_qualite: { label: 'En attente — qualité', background: '#fef3c7', color: '#b45309' },
  published: { label: 'Publié', background: '#dcfce7', color: '#15803d' },
  archived: { label: 'Archivé', background: '#e2e8f0', color: '#334155' },
};

/** Which `format_*_drive_id` column backs a given file slot. */
export function articleFileId(article: Article, format: ArticleFileFormat): string | null {
  switch (format) {
    case 'pdf':
      return article.format_pdf_drive_id;
    case 'infographie':
      return article.format_infographie_drive_id;
    case 'video':
      return article.format_video_drive_id;
  }
}

// --------------------------------------------------------------------------
// Client-side file upload validation (pdf/infographie/video slots).
// Same caps as ArticleFileFormat::validationRule() server-side
// (backend/app/Enums/ArticleFileFormat.php) — this exists to fail fast and
// give a message before a 100 MB body goes over the wire, not to replace the
// server's own check. Same shape as procedure.model.ts's FormatSpec/
// validateFile, kept separate rather than shared: the two features' slots
// happen to have similar rules today, not a real dependency between them.
// --------------------------------------------------------------------------

export interface ArticleFileFormatSpec {
  format: ArticleFileFormat;
  label: string;
  hint: string;
  icon: string;
  /** `accept` attribute for the native file input. */
  accept: string;
  /** MIME types the server will actually admit. */
  mimeTypes: string[];
  /** Max size in bytes. */
  maxBytes: number;
  /** Human-readable size cap, for hints and error messages. */
  maxLabel: string;
}

const MB = 1024 * 1024;

export const ARTICLE_FILE_FORMAT_SPECS: Record<ArticleFileFormat, ArticleFileFormatSpec> = {
  pdf: {
    format: 'pdf',
    label: 'PDF',
    hint: 'Document de référence — obligatoire, source de vérité réglementaire.',
    icon: 'file',
    accept: '.pdf,application/pdf',
    mimeTypes: ['application/pdf'],
    maxBytes: 20 * MB,
    maxLabel: '20 Mo',
  },
  infographie: {
    format: 'infographie',
    label: 'Infographie',
    hint: 'Schéma de synthèse — PNG, JPEG ou WebP.',
    icon: 'image',
    accept: '.png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp',
    mimeTypes: ['image/png', 'image/jpeg', 'image/webp'],
    maxBytes: 10 * MB,
    maxLabel: '10 Mo',
  },
  video: {
    format: 'video',
    label: 'Vidéo',
    hint: 'Tutoriel vidéo — MP4 ou WebM.',
    icon: 'video',
    accept: '.mp4,.webm,video/mp4,video/webm',
    mimeTypes: ['video/mp4', 'video/webm'],
    maxBytes: 100 * MB,
    maxLabel: '100 Mo',
  },
};

export const ARTICLE_FILE_FORMAT_ORDER: ArticleFileFormat[] = ['pdf', 'infographie', 'video'];

/** Format a byte count for display (1.4 Mo, 812 Ko…). */
export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} o`;
  if (bytes < MB) return `${Math.round(bytes / 1024)} Ko`;
  return `${(bytes / MB).toFixed(1)} Mo`;
}

/**
 * Validate a dropped/selected file against its slot.
 * Returns a French error message, or null when the file is acceptable.
 *
 * Note the empty-type fallback: some browsers report `type: ''` for files
 * dragged from certain sources, so this falls back to the extension rather
 * than rejecting a legitimate file outright. The server re-checks by
 * sniffing content, so a spoofed extension still gets caught there.
 */
export function validateArticleFile(file: File, spec: ArticleFileFormatSpec): string | null {
  if (file.size === 0) {
    return 'Le fichier est vide.';
  }

  if (file.size > spec.maxBytes) {
    return `Fichier trop volumineux (${formatBytes(file.size)}). Maximum : ${spec.maxLabel}.`;
  }

  const declared = (file.type || '').toLowerCase();
  if (declared) {
    if (!spec.mimeTypes.includes(declared)) {
      return `Type de fichier non autorisé (${declared}). Attendu : ${spec.mimeTypes.join(', ')}.`;
    }
    return null;
  }

  const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
  const allowedExts = spec.accept
    .split(',')
    .filter(part => part.startsWith('.'))
    .map(part => part.slice(1));

  if (!allowedExts.includes(ext)) {
    return `Extension non autorisée (.${ext}). Attendu : ${allowedExts.map(e => '.' + e).join(', ')}.`;
  }

  return null;
}
