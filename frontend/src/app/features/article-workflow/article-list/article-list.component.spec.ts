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
    author: { id: 1, name: 'Auteur', email: 'auteur@aziza.com' },
    ...overrides,
  };
}

describe('ArticleWorkflowListComponent', () => {
  let component: ArticleWorkflowListComponent;
  let fixture: ComponentFixture<ArticleWorkflowListComponent>;

  /**
   * AuthService reads localStorage in a field initializer, so a session has to
   * be in place before TestBed instantiates it. `role` must be a known DemoRole
   * or readUser() discards the whole session as stale.
   */
  function seedSession(accessRole: string | null): void {
    localStorage.setItem('auth_token', 'test-token');
    localStorage.setItem(
      'current_user',
      JSON.stringify({
        id: 1,
        name: 'Testeur',
        email: 'testeur@aziza.com',
        role: 'expert_metier',
        ...(accessRole ? { access_role: accessRole } : {}),
      })
    );
  }

  function build(): void {
    TestBed.configureTestingModule({
      imports: [ArticleWorkflowListComponent],
      // ArticleApiService needs HttpClient; the template's routerLink needs a
      // Router. No spec elsewhere in this project exercises an HttpClient-backed
      // service yet, so there's no existing convention to match here.
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
    });

    fixture = TestBed.createComponent(ArticleWorkflowListComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  }

  beforeEach(() => {
    localStorage.clear();
    build();
  });

  afterEach(() => {
    localStorage.clear();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });

  /**
   * The other half of the §10.2 shortcut block: it is scoped to document
   * viewers, and this list is an ordinary page. Copying a reference out of it,
   * saving it or printing it are all legitimate — the spec asks for the
   * document content to be protected, not for the Hub to become a keyboard
   * trap. If someone ever moves BlockCopyShortcutsDirective to a layout or the
   * app root, this fails.
   */
  it('leaves the copy, save and print shortcuts alone on an ordinary page', () => {
    for (const key of ['c', 's', 'p']) {
      const event = new KeyboardEvent('keydown', {
        key,
        ctrlKey: true,
        bubbles: true,
        cancelable: true,
      });
      document.dispatchEvent(event);

      expect(event.defaultPrevented).withContext(`Ctrl+${key} was blocked`).toBeFalse();
    }
  });

  /**
   * The "Nouvel article" affordance. /dashboard/articles/new has no route
   * guard, so this button is the only thing standing between a lecteur and a
   * create form they'd be 403'd out of — worth pinning per role.
   */
  describe('create affordance', () => {
    /** Rebuilds the component against a session with the given access_role. */
    function buildAs(accessRole: string | null): void {
      TestBed.resetTestingModule();
      localStorage.clear();
      seedSession(accessRole);
      build();
    }

    function createLink(): HTMLAnchorElement | null {
      return fixture.nativeElement.querySelector('a[href="/dashboard/articles/new"]');
    }

    it('shows the button for a redacteur', () => {
      buildAs('redacteur');
      expect(component.canCreateArticle()).toBeTrue();
      expect(createLink()?.textContent).toContain('Nouvel article');
    });

    // Matches the backend Gate, which is hasRole(['redacteur', 'admin']).
    it('shows the button for an admin', () => {
      buildAs('admin');
      expect(component.canCreateArticle()).toBeTrue();
      expect(createLink()).not.toBeNull();
    });

    it('hides the button for roles the create-articles Gate would refuse', () => {
      for (const role of ['lecteur', 'qualite', 'responsable_departement', 'data_owner']) {
        buildAs(role);
        expect(component.canCreateArticle())
          .withContext(`access_role=${role}`)
          .toBeFalse();
        expect(createLink()).withContext(`access_role=${role}`).toBeNull();
      }
    });

    // A session cached before access_role started being persisted. Reads as
    // "cannot" until backfillAccessRole() fills it in — the safe direction.
    it('hides the button when access_role is missing entirely', () => {
      buildAs(null);
      expect(component.canCreateArticle()).toBeFalse();
      expect(createLink()).toBeNull();
    });

    /**
     * The header sits outside the loading/error/empty branches, so the button
     * has to survive the empty state — which is precisely when a rédacteur
     * most needs it.
     */
    it('still offers the button when there are no articles at all', () => {
      buildAs('redacteur');
      component.isLoading.set(false);
      component.articles.set([]);
      fixture.detectChanges();

      expect(fixture.nativeElement.textContent).toContain('Aucun article pour le moment');
      expect(createLink()).not.toBeNull();
    });
  });

  describe('filtering', () => {
    // The slug/content_summary/tags_metier values are chosen so that each new
    // searchable field can be hit *in isolation* — no term used below matches
    // two fields of the same article by accident, which is what lets these
    // tests prove which field did the matching.
    const securite = makeArticle({
      id: 'a1',
      title: 'Procédure de sécurité',
      slug: 'procedure-de-securite',
      content_summary: 'Port des équipements de protection individuelle.',
      tags_metier: ['HSE', 'Atelier', 'Prévention'],
      status: 'published',
      criticite: 'golden_rule',
    });
    const qualite = makeArticle({
      id: 'a2',
      title: 'Contrôle qualité',
      slug: 'controle-qualite',
      content_summary: 'Échantillonnage à la réception des matières.',
      tags_metier: ['Qualité', 'Controle Statistique'],
      status: 'pending_metier',
      criticite: 'note',
    });
    // Left with content_summary: null and tags_metier: [] on purpose — the
    // nullable summary and the empty array are the two shapes that would throw
    // if the new fields were fed to normalize() unguarded, so every query below
    // is also a smoke test for them.
    const archive = makeArticle({
      id: 'a3',
      title: 'Ancienne procedure',
      slug: 'ancienne-procedure',
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

    // ------------------------------------------------------- spec §5.1 fields
    // Search must reach metadata, not just the title. Each test below picks a
    // term that appears in exactly one field, and asserts the *other* fields
    // really don't contain it — otherwise a passing test would prove nothing
    // about which field the match came from.

    it('matches on content_summary when the title does not', () => {
      component.searchTerm.set('protection');

      expect(securite.title.toLowerCase()).not.toContain('protection');
      expect(securite.slug).not.toContain('protection');
      expect(securite.tags_metier.join(' ').toLowerCase()).not.toContain('protection');

      expect(titles()).toEqual(['Procédure de sécurité']);
    });

    it('matches on a tag when neither title nor summary does', () => {
      component.searchTerm.set('atelier');

      expect(securite.title.toLowerCase()).not.toContain('atelier');
      expect(securite.content_summary!.toLowerCase()).not.toContain('atelier');

      expect(titles()).toEqual(['Procédure de sécurité']);
    });

    it('matches on slug when the title does not', () => {
      // The hyphenated form exists only in the slug: the title is
      // "Contrôle qualité", which normalizes to "controle qualite" — a space,
      // not a hyphen — so this term can only come from the slug.
      component.searchTerm.set('controle-qualite');

      expect(titles()).toEqual(['Contrôle qualité']);
    });

    it('is accent-insensitive on content_summary, both directions', () => {
      // Unaccented query against an accented summary ("équipements").
      component.searchTerm.set('equipements');
      expect(titles()).toEqual(['Procédure de sécurité']);

      // And uppercase+accented query against the same summary.
      component.searchTerm.set('ÉCHANTILLONNAGE');
      expect(titles()).toEqual(['Contrôle qualité']);
    });

    it('is accent-insensitive on tags, both directions', () => {
      // Unaccented query against the accented tag "Prévention", which appears
      // in no other field of that article.
      component.searchTerm.set('prevention');
      expect(securite.title.toLowerCase()).not.toContain('prevention');
      expect(securite.content_summary!.toLowerCase()).not.toContain('prevention');
      expect(titles()).toEqual(['Procédure de sécurité']);

      // The reverse: accented query against the deliberately *unaccented* tag
      // "Controle Statistique" — a term the title "Contrôle qualité" does not
      // contain, so the tag is the only possible source of the match.
      component.searchTerm.set('CONTRÔLE STATISTIQUE');
      expect(titles()).toEqual(['Contrôle qualité']);
    });

    it('leaves an article with a null summary and no tags searchable by title', () => {
      // Guards the nullable/empty shapes: these must not throw, and must not
      // start matching everything either.
      expect(archive.content_summary).toBeNull();
      expect(archive.tags_metier).toEqual([]);

      component.searchTerm.set('ancienne');
      expect(titles()).toEqual(['Ancienne procedure']);

      component.searchTerm.set('protection');
      expect(titles()).not.toContain('Ancienne procedure');
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

    /**
     * The regression this guards: widening the search must stay OR *within*
     * the query and AND *against* the chips. If the new fields had been folded
     * in as another OR branch at the top level, a summary or tag hit would
     * start overriding the status/criticite chips entirely.
     */
    it('ANDs a summary match with the status and criticite filters', () => {
      component.searchTerm.set('protection');
      component.statusFilter.set('published');
      component.criticiteFilter.set('golden_rule');
      expect(titles()).toEqual(['Procédure de sécurité']);

      // The summary still matches, but the status no longer does.
      component.statusFilter.set('draft');
      expect(titles()).toEqual([]);

      // Status restored, criticite flipped instead.
      component.statusFilter.set('published');
      component.criticiteFilter.set('note');
      expect(titles()).toEqual([]);
    });

    it('ANDs a tag match with the status and criticite filters', () => {
      component.searchTerm.set('atelier');
      component.statusFilter.set('published');
      expect(titles()).toEqual(['Procédure de sécurité']);

      component.statusFilter.set('archived');
      expect(titles()).toEqual([]);
    });

    it('ANDs a slug match with the status filter', () => {
      component.searchTerm.set('controle-qualite');
      component.statusFilter.set('pending_metier');
      expect(titles()).toEqual(['Contrôle qualité']);

      component.statusFilter.set('published');
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
