/**
 * Tiny localStorage-backed persistence helpers for the frontend-only demo.
 * Every "service" reads/writes JSON arrays through these.
 */

export const STORE_KEYS = {
  articles: 'kh_articles',
  hrRequests: 'kh_hr_requests',
  procedures: 'kh_procedures',
  kaizen: 'kh_kaizen',
  notifications: 'kh_notifications',
} as const;

/**
 * Read a JSON value; if absent, seed it and return the seed.
 *
 * Every `localStorage` access is guarded: the Storage API throws (not returns
 * null) when storage is unavailable — blocked by browser privacy settings, or
 * `QuotaExceededError` on write once the store grows. Previously those throws
 * escaped synchronously out of the calling service *before* its `of(...)`
 * observable was constructed, so `.subscribe({ error })` could never catch them
 * and list components were left stuck on "Chargement..." forever. Degrading to
 * the in-memory seed keeps the demo usable instead.
 */
export function lsRead<T>(key: string, seed: T): T {
  let raw: string | null = null;
  try {
    raw = localStorage.getItem(key);
  } catch (err) {
    console.warn(`[local-store] lecture impossible pour "${key}" :`, err);
    return seed;
  }

  if (raw !== null) {
    try {
      return JSON.parse(raw) as T;
    } catch {
      /* corrupt value — fall through and re-seed */
    }
  }
  lsWrite(key, seed);
  return seed;
}

/** Overwrite a JSON value. Never throws — storage failures are logged, not fatal. */
export function lsWrite<T>(key: string, value: T): void {
  try {
    localStorage.setItem(key, JSON.stringify(value));
  } catch (err) {
    console.warn(`[local-store] écriture impossible pour "${key}" :`, err);
  }
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
