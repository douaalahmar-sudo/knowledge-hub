/**
 * Procedure / triptych domain types.
 *
 * Mirrors the Laravel `procedures` table plus the `triptych_urls` accessor the
 * model appends on every response (see backend/app/Models/Procedure.php).
 */

/** The three formats a procedure can be published in. */
export type TriptychFormat = 'pdf' | 'video' | 'infographic';

/** Absolute, ready-to-bind asset URLs; null when that format was never uploaded. */
export interface TriptychUrls {
  pdf: string | null;
  video: string | null;
  infographic: string | null;
}

export interface Procedure {
  id: number;
  reference_code: string;
  name: string;
  module: string;
  category: string | null;
  department: string | null;
  description: string | null;
  status: string;
  version: string;
  is_active: boolean;

  pdf_path: string | null;
  video_path: string | null;
  infographic_path: string | null;
  triptych_urls: TriptychUrls;

  created_by?: number;
  tenant_id?: number;
  created_at?: string;
  updated_at?: string;
  creator?: { id: number; name: string } | null;
}

/** Body accepted by POST /v1/procedures and PATCH /v1/procedures/{id}. */
export interface ProcedurePayload {
  reference_code: string;
  name: string;
  module: string;
  category?: string | null;
  department?: string | null;
  description?: string | null;
  status?: string;
  version?: string;
  pdf_path?: string | null;
  video_path?: string | null;
  infographic_path?: string | null;
}

/** Shape returned by POST /v1/procedures/upload-triptych. */
export interface TriptychUploadResult {
  message: string;
  paths: Partial<Record<'pdf_path' | 'video_path' | 'infographic_path', string>>;
  urls: Record<string, string>;
  procedure: Procedure | null;
}

/**
 * Per-slot upload rules. These MUST stay in step with the server-side rules in
 * TriptychUploadController — the client copy exists to fail fast and give the
 * user a message before a 100 MB body goes over the wire, not to replace them.
 */
export interface FormatSpec {
  /** Form field name the API expects. */
  field: 'pdf_file' | 'video_file' | 'infographic_file';
  /** Column the returned path lands in. */
  pathKey: 'pdf_path' | 'video_path' | 'infographic_path';
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

export const TRIPTYCH_SPECS: Record<TriptychFormat, FormatSpec> = {
  pdf: {
    field: 'pdf_file',
    pathKey: 'pdf_path',
    label: 'Document PDF',
    hint: 'La procédure détaillée au format imprimable.',
    icon: 'file',
    accept: '.pdf,application/pdf',
    mimeTypes: ['application/pdf'],
    maxBytes: 20 * MB,
    maxLabel: '20 Mo',
  },
  video: {
    field: 'video_file',
    pathKey: 'video_path',
    label: 'Vidéo explicative',
    hint: 'Tutoriel vidéo — MP4 ou WebM.',
    icon: 'video',
    accept: '.mp4,.webm,video/mp4,video/webm',
    mimeTypes: ['video/mp4', 'video/webm'],
    maxBytes: 100 * MB,
    maxLabel: '100 Mo',
  },
  infographic: {
    field: 'infographic_file',
    pathKey: 'infographic_path',
    label: 'Infographie / Schéma',
    hint: 'Schéma de synthèse haute résolution.',
    icon: 'image',
    accept: '.png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp',
    mimeTypes: ['image/png', 'image/jpeg', 'image/webp'],
    maxBytes: 10 * MB,
    maxLabel: '10 Mo',
  },
};

export const TRIPTYCH_ORDER: TriptychFormat[] = ['pdf', 'video', 'infographic'];

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
 * dragged from certain sources, so we fall back to the extension rather than
 * rejecting a legitimate file outright. The server re-checks by sniffing
 * content, so a spoofed extension still gets caught there.
 */
export function validateFile(file: File, spec: FormatSpec): string | null {
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
