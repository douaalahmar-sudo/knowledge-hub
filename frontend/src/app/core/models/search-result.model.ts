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
  PROCEDURE: {
    label: 'Procédure', labelPlural: 'Procédures', icon: 'policy',
    iconClass: 'bg-indigo-50 text-indigo-600', badgeClass: 'bg-indigo-100 text-indigo-700'
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
