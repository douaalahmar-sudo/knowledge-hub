import { Component, ElementRef, HostListener, OnDestroy, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import { Subject, Subscription, debounceTime, distinctUntilChanged, switchMap, of, catchError } from 'rxjs';
import { SearchService } from '../../../core/services/search.service';
import { SearchResultItem, SEARCH_TYPE_META, SEARCH_GROUP_ORDER } from '../../../core/models/search-result.model';
import { IconComponent } from '../../icon/icon.component';


interface DropdownGroup {
  type: string;
  label: string;
  icon: string;
  iconClass: string;
  items: SearchResultItem[];
}


@Component({
  selector: 'app-global-search',
  standalone: true,
  imports: [CommonModule, IconComponent],
  templateUrl: './global-search.component.html'
})
export class GlobalSearchComponent implements OnDestroy {
  private searchService = inject(SearchService);
  private router = inject(Router);
  private host = inject(ElementRef);

  query = signal('');
  groups = signal<DropdownGroup[]>([]);
  isOpen = signal(false);
  isLoading = signal(false);
  hasResults = signal(false);

  private input$ = new Subject<string>();
  private sub: Subscription;

  constructor() {
    this.sub = this.input$.pipe(
      debounceTime(300),
      distinctUntilChanged(),
      switchMap(q => {
        const term = q.trim();
        if (term.length < 2) {
          this.isLoading.set(false);
          return of(null);
        }
        this.isLoading.set(true);
        return this.searchService.search(term).pipe(
          catchError(() => of(null))
        );
      })
    ).subscribe(res => {
      this.isLoading.set(false);
      if (!res) {
        this.groups.set([]);
        this.hasResults.set(false);
        return;
      }
      // Top 3 per non-empty category.
      const groups: DropdownGroup[] = SEARCH_GROUP_ORDER
        .map(({ key, type }) => {
          const meta = SEARCH_TYPE_META[type];
          return {
            type,
            label: meta.labelPlural,
            icon: meta.icon,
            iconClass: meta.iconClass,
            items: (res.results[key] || []).slice(0, 3)
          };
        })
        .filter(g => g.items.length > 0);
      this.groups.set(groups);
      this.hasResults.set(groups.length > 0);
    });
  }

  onInput(value: string): void {
    this.query.set(value);
    this.isOpen.set(true);
    this.input$.next(value);
  }

  onFocus(): void {
    if (this.query().trim().length >= 2) this.isOpen.set(true);
  }

  onEnter(): void {
    const term = this.query().trim();
    if (!term) return;
    this.goToResults(term);
  }

  selectItem(item: SearchResultItem): void {
    this.close();
    this.router.navigateByUrl(item.url);
  }

  goToResults(term = this.query().trim()): void {
    if (!term) return;
    this.close();
    this.router.navigate(['/dashboard/search'], { queryParams: { q: term } });
  }

  close(): void {
    this.isOpen.set(false);
  }

  @HostListener('document:click', ['$event'])
  onDocumentClick(event: MouseEvent): void {
    if (!this.host.nativeElement.contains(event.target)) {
      this.close();
    }
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    this.close();
  }

  ngOnDestroy(): void {
    this.sub.unsubscribe();
  }
}
