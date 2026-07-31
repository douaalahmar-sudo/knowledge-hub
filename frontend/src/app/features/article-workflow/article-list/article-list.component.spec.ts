import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';

import { Article } from '../../../core/models/article.model';
import { ArticleWorkflowListComponent } from './article-list.component';

/** Only the fields the filters actually read need to be meaningful. */
function makeArticle(overrides: Partial<Article>): Article {
  return {
    id: 'a1',
    filiale_id: 'f1',
    title: 'Titre',
    slug: 'titre',
    content_summary: null,
    tags_metier: [],
    criticite: 'note',
    status: 'draft',
    format_pdf_drive_id: null,
    format_infographie_drive_id: null,
    format_video_drive_id: null,
    version: 1,
    is_active_version: true,
    parent_article_id: null,
    author_id: 1,
    validated_by_metier_id: null,
    validated_by_qualite_id: null,
    data_owner_id: 1,
    published_at: null,
    created_at: '2026-07-01T00:00:00Z',
    updated_at: '2026-07-01T00:00:00Z',
    author: { id: 1, name: 'Auteur', email: 'auteur@flesk.com' },
    ...overrides,
  };
}

describe('ArticleWorkflowListComponent', () => {
  let component: ArticleWorkflowListComponent;
  let fixture: ComponentFixture<ArticleWorkflowListComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ArticleWorkflowListComponent],
      // ArticleApiService needs HttpClient; the template's routerLink needs a
      // Router. No spec elsewhere in this project exercises an HttpClient-backed
      // service yet, so there's no existing convention to match here.
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
    }).compileComponents();

    fixture = TestBed.createComponent(ArticleWorkflowListComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });

  describe('filtering', () => {
    const securite = makeArticle({
      id: 'a1',
      title: 'Procédure de sécurité',
      status: 'published',
      criticite: 'golden_rule',
    });
    const qualite = makeArticle({
      id: 'a2',
      title: 'Contrôle qualité',
      status: 'pending_metier',
      criticite: 'note',
    });
    const archive = makeArticle({
      id: 'a3',
      title: 'Ancienne procedure',
      status: 'archived',
      criticite: 'note',
    });

    function titles(): string[] {
      return component.filteredArticles().map(a => a.title);
    }

    beforeEach(() => {
      component.articles.set([securite, qualite, archive]);
    });

    it('returns everything when no filter is set', () => {
      expect(component.filteredArticles().length).toBe(3);
      expect(component.hasActiveFilters()).toBeFalse();
    });

    it('filters by title, case- and accent-insensitively', () => {
      // Unaccented query against an accented title...
      component.searchTerm.set('procedure');
      expect(titles()).toEqual(['Procédure de sécurité', 'Ancienne procedure']);

      // ...and the reverse, accented query against an unaccented title.
      component.searchTerm.set('ANCIENNE PROCÉDURE');
      expect(titles()).toEqual(['Ancienne procedure']);
    });

    it('filters by status', () => {
      component.statusFilter.set('archived');
      expect(titles()).toEqual(['Ancienne procedure']);
    });

    it('filters by criticite', () => {
      component.criticiteFilter.set('golden_rule');
      expect(titles()).toEqual(['Procédure de sécurité']);
    });

    it('combines all three with AND', () => {
      component.searchTerm.set('procedure');
      component.statusFilter.set('published');
      component.criticiteFilter.set('golden_rule');
      expect(titles()).toEqual(['Procédure de sécurité']);

      // Each filter alone would match it; flipping one to a non-matching
      // value must exclude it, which a filter OR'd together would not.
      component.criticiteFilter.set('note');
      expect(titles()).toEqual([]);
    });

    it('distinguishes "no articles" from "no match" via hasActiveFilters', () => {
      component.searchTerm.set('zzz-introuvable');
      expect(component.filteredArticles().length).toBe(0);
      expect(component.articles().length).toBe(3);
      expect(component.hasActiveFilters()).toBeTrue();
    });

    it('resetFilters clears all three', () => {
      component.searchTerm.set('procedure');
      component.statusFilter.set('archived');
      component.criticiteFilter.set('note');
      expect(component.hasActiveFilters()).toBeTrue();

      component.resetFilters();

      expect(component.hasActiveFilters()).toBeFalse();
      expect(component.filteredArticles().length).toBe(3);
    });
  });
});
