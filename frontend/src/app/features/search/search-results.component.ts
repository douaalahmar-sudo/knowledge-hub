import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, RouterModule } from '@angular/router';
import { DomSanitizer, SafeHtml } from '@angular/platform-browser';
import { SearchService } from '../../core/services/search.service';
import { SearchResultItem, SearchEntityType, SEARCH_TYPE_META } from '../../core/models/search-result.model';
import { IconComponent } from '../../shared/icon/icon.component';


type TypeFilter = 'ALL' | SearchEntityType;
type DatePreset = 'ALL' | '7' | '30' | 'YEAR';


@Component({
  selector: 'app-search-results',
  standalone: true,
  imports: [CommonModule, RouterModule, IconComponent],
  templateUrl: './search-results.component.html'
})
export class SearchResultsComponent implements OnInit {
  private route = inject(ActivatedRoute);
  private searchService = inject(SearchService);
  private sanitizer = inject(DomSanitizer);

  typeMeta = SEARCH_TYPE_META;

  query = signal('');
  allResults = signal<SearchResultItem[]>([]);
  isLoading = signal(false);

  // Active facets
  typeFilter = signal<TypeFilter>('ALL');
  authorFilter = signal<string>('');
  tenantFilter = signal<string>('');
  datePreset = signal<DatePreset>('ALL');

  ngOnInit(): void {
    // React to ?q= changes (e.g. navigating from the top bar again).
    this.route.queryParamMap.subscribe(params => {
      const q = (params.get('q') || '').trim();
      this.query.set(q);
      this.resetFacets();
      if (q.length >= 1) {
        this.runSearch(q);
      } else {
        this.allResults.set([]);
      }
    });
  }

  private resetFacets(): void {
    this.typeFilter.set('ALL');
    this.authorFilter.set('');
    this.tenantFilter.set('');
    this.datePreset.set('ALL');
  }

  private runSearch(q: string): void {
    this.isLoading.set(true);
    this.searchService.search(q).subscribe({
      next: (res) => {
        const flat = [
          ...res.results.procedures,
          ...res.results.articles,
          ...res.results.kaizen,
          ...res.results.hr_requests
        ];
        this.allResults.set(flat);
        this.isLoading.set(false);
      },
      error: () => {
        this.allResults.set([]);
        this.isLoading.set(false);
      }
    });
  }

  // ---- Facet option lists (derived from results) ----
  typeTabs = computed(() => {
    const all = this.allResults();
    const counts: Record<string, number> = {};
    for (const r of all) counts[r.entity_type] = (counts[r.entity_type] || 0) + 1;
    return [
      { value: 'ALL' as TypeFilter, label: 'Tous', count: all.length },
      { value: 'PROCEDURE' as TypeFilter, label: 'Procédures', count: counts['PROCEDURE'] || 0 },
      { value: 'ARTICLE' as TypeFilter, label: 'Articles', count: counts['ARTICLE'] || 0 },
      { value: 'KAIZEN' as TypeFilter, label: 'Kaizen', count: counts['KAIZEN'] || 0 },
      { value: 'HR_REQUEST' as TypeFilter, label: 'Demandes RH', count: counts['HR_REQUEST'] || 0 },
    ];
  });

  authors = computed(() =>
    Array.from(new Set(this.allResults().map(r => r.author).filter((a): a is string => !!a))).sort()
  );

  tenants = computed(() =>
    Array.from(new Set(this.allResults().map(r => r.tenant_location).filter((t): t is string => !!t))).sort()
  );

  // ---- Filtered result set ----
  filtered = computed(() => {
    const type = this.typeFilter();
    const author = this.authorFilter();
    const tenant = this.tenantFilter();
    const cutoff = this.dateCutoff();

    return this.allResults().filter(r => {
      if (type !== 'ALL' && r.entity_type !== type) return false;
      if (author && r.author !== author) return false;
      if (tenant && r.tenant_location !== tenant) return false;
      if (cutoff && r.created_at && new Date(r.created_at) < cutoff) return false;
      return true;
    });
  });

  private dateCutoff(): Date | null {
    const preset = this.datePreset();
    if (preset === 'ALL') return null;
    const now = new Date();
    if (preset === '7') return new Date(now.getTime() - 7 * 864e5);
    if (preset === '30') return new Date(now.getTime() - 30 * 864e5);
    if (preset === 'YEAR') return new Date(now.getFullYear(), 0, 1);
    return null;
  }

  // ---- Facet setters ----
  setType(t: TypeFilter): void { this.typeFilter.set(t); }
  setAuthor(a: string): void { this.authorFilter.set(a); }
  setTenant(t: string): void { this.tenantFilter.set(t); }
  setDatePreset(p: DatePreset): void { this.datePreset.set(p); }

  clearFilters(): void {
    this.resetFacets();
  }

  hasActiveFilters = computed(() =>
    this.typeFilter() !== 'ALL' || !!this.authorFilter() || !!this.tenantFilter() || this.datePreset() !== 'ALL'
  );

  // ---- Snippet highlighting ----
  highlight(text: string | null | undefined): SafeHtml {
    const raw = text || '';
    const term = this.query().trim();
    const escaped = this.escapeHtml(raw);
    if (!term) return this.sanitizer.bypassSecurityTrustHtml(escaped);
    const safeTerm = term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const highlighted = escaped.replace(
      new RegExp(`(${safeTerm})`, 'gi'),
      '<mark class="bg-yellow-200 rounded px-0.5">$1</mark>'
    );
    return this.sanitizer.bypassSecurityTrustHtml(highlighted);
  }

  private escapeHtml(s: string): string {
    return s
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
}
