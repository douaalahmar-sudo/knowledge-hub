/**
 * Tiny localStorage-backed persistence helpers for the frontend-only demo.
 * Every "service" reads/writes JSON arrays through these.
 */

export const STORE_KEYS = {
  articles: 'kh_articles',
  hrRequests: 'kh_hr_requests',
  procedures: 'kh_procedures',
  kaizen: 'kh_kaizen',
} as const;

/** Read a JSON value; if absent, seed it and return the seed. */
export function lsRead<T>(key: string, seed: T): T {
  const raw = localStorage.getItem(key);
  if (raw !== null) {
    try {
      return JSON.parse(raw) as T;
    } catch {
      /* corrupt value — fall through and re-seed */
    }
  }
  localStorage.setItem(key, JSON.stringify(seed));
  return seed;
}

/** Overwrite a JSON value. */
export function lsWrite<T>(key: string, value: T): void {
  localStorage.setItem(key, JSON.stringify(value));
}

/** Generate a short unique id. */
export function uid(prefix = ''): string {
  return prefix + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
}

/** Slugify a title for article URLs. */
export function slugify(input: string): string {
  const base = (input || '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
  return base || 'article';
}
