export type SearchEntityType = 'PROCEDURE' | 'ARTICLE' | 'KAIZEN' | 'HR_REQUEST';

export interface SearchResultItem {
  id: string | number;
  title: string;
  description?: string | null;
  entity_type: SearchEntityType;
  author?: string | null;
  tenant_location?: string | null;
  created_at?: string | null;
  url: string;
  badge?: string | null;
}

export interface SearchResponse {
  results: {
    procedures: SearchResultItem[];
    articles: SearchResultItem[];
    kaizen: SearchResultItem[];
    hr_requests: SearchResultItem[];
  };
}

export interface SearchQueryParams {
  type?: string;
  author_id?: string | number;
  date_from?: string;
  date_to?: string;
}

/** Presentation metadata per entity type (icon name + label + badge classes). */
export const SEARCH_TYPE_META: Record<SearchEntityType, {
  label: string;
  labelPlural: string;
  icon: string;
  iconClass: string;   // color for the icon tile
  badgeClass: string;  // color for the small type badge
}> = {
  // 'violet' rather than 'indigo': tailwind.config.js remaps the `indigo`
  // palette to the app's orange brand accent, which every button/link/focus
  // ring in the app now shares. This legend needs four colours that read as
  // distinct from *each other* and from that shared brand colour, so
  // PROCEDURE uses the one Tailwind hue nothing else here is aliased to.
  PROCEDURE: {
    label: 'Procédure', labelPlural: 'Procédures', icon: 'policy',
    iconClass: 'bg-violet-50 text-violet-600', badgeClass: 'bg-violet-100 text-violet-700'
  },
  ARTICLE: {
    label: 'Article', labelPlural: 'Articles', icon: 'book',
    iconClass: 'bg-blue-50 text-blue-600', badgeClass: 'bg-blue-100 text-blue-700'
  },
  KAIZEN: {
    label: 'Kaizen', labelPlural: 'Kaizen', icon: 'alert',
    iconClass: 'bg-amber-50 text-amber-600', badgeClass: 'bg-amber-100 text-amber-700'
  },
  HR_REQUEST: {
    label: 'Demande RH', labelPlural: 'Demandes RH', icon: 'inbox',
    iconClass: 'bg-green-50 text-green-600', badgeClass: 'bg-green-100 text-green-700'
  },
};

/** The result-group keys in the API response, in display order. */
export const SEARCH_GROUP_ORDER: { key: keyof SearchResponse['results']; type: SearchEntityType }[] = [
  { key: 'procedures',  type: 'PROCEDURE' },
  { key: 'articles',    type: 'ARTICLE' },
  { key: 'kaizen',      type: 'KAIZEN' },
  { key: 'hr_requests', type: 'HR_REQUEST' },
];
