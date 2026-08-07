import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';

import { ArticleWorkflowDetailComponent } from './article-detail.component';
import { Article } from '../../../core/models/article.model';
import { environment } from '../../../../environments/environment';

const ME_URL = `${environment.apiUrl}/v1/auth/me`;
const ARTICLE_URL = `${environment.apiUrl}/v1/articles/test-id`;
const FILE_URL = `${environment.apiUrl}/v1/articles/test-id/files/pdf`;

/** Minimal article with one attached format, so the viewer renders the overlay. */
const articleFixture = (overrides: Partial<Article> = {}): Article =>
  ({
    id: 'test-id',
    filiale_id: 'f-1',
    title: 'Procédure de sécurité',
    slug: 'procedure-de-securite',
    content_summary: null,
    tags_metier: [],
    criticite: 'note',
    status: 'published',
    format_pdf_drive_id: 'drive-pdf-1',
    format_infographie_drive_id: null,
    format_video_drive_id: null,
    version: 1,
    is_active_version: true,
    parent_article_id: null,
    author_id: 1,
    validated_by_metier_id: null,
    validated_by_qualite_id: null,
    data_owner_id: 1,
    published_at: '2026-08-01T10:00:00Z',
    created_at: '2026-08-01T10:00:00Z',
    updated_at: '2026-08-01T10:00:00Z',
    author: { id: 1, name: 'Auteur', email: 'auteur@flesk.com' },
    ...overrides,
  }) as Article;

describe('ArticleWorkflowDetailComponent', () => {
  let component: ArticleWorkflowDetailComponent;
  let fixture: ComponentFixture<ArticleWorkflowDetailComponent>;
  let httpMock: HttpTestingController;

  /**
   * AuthService reads localStorage in a field initializer, so the session has
   * to exist before TestBed instantiates it — hence the seeding here rather
   * than inside the individual tests.
   */
  function setUp(session: Record<string, unknown> | null): void {
    localStorage.clear();
    if (session) {
      localStorage.setItem('auth_token', 'test-token');
      localStorage.setItem('current_user', JSON.stringify(session));
    }

    TestBed.configureTestingModule({
      imports: [ArticleWorkflowDetailComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        {
          provide: ActivatedRoute,
          useValue: { snapshot: { paramMap: convertToParamMap({ id: 'test-id' }) } },
        },
      ],
    });

    fixture = TestBed.createComponent(ArticleWorkflowDetailComponent);
    component = fixture.componentInstance;
    httpMock = TestBed.inject(HttpTestingController);
    fixture.detectChanges();
  }

  /** Drives the viewer all the way to a rendered document + overlay. */
  function renderViewer(): void {
    httpMock.expectOne(ARTICLE_URL).flush(articleFixture());
    fixture.detectChanges();
    httpMock.expectOne(FILE_URL).flush(new Blob(['%PDF-1.4'], { type: 'application/pdf' }));
    fixture.detectChanges();
  }

  /**
   * `.dw__line` since the overlay moved into the shared
   * DocumentWatermarkComponent; it used to be this component's own
   * `.ad-watermark__line`. The assertions below are unchanged — the point of
   * keeping them here is that the extraction did not weaken §10.3 for articles.
   */
  function renderedWatermark(): string {
    const line: HTMLElement | null = fixture.nativeElement.querySelector('.dw__line');
    return line?.textContent?.trim() ?? '';
  }

  afterEach(() => {
    localStorage.clear();
    TestBed.resetTestingModule();
  });

  it('should create', () => {
    setUp({ name: 'Test', email: 't@flesk.com', role: 'admin' });
    httpMock.expectOne(ME_URL).flush({ client_ip: '10.0.0.1' });
    expect(component).toBeTruthy();
  });

  /**
   * The cahier des charges §10.3 assertion: the overlay must actually carry
   * all four fields, in order, in the DOM the reader sees.
   *   [Nom complet] | [Matricule] | [Adresse IP] | [Horodatage à la seconde]
   */
  it('renders name, matricule, IP and a to-the-second timestamp in the watermark', () => {
    setUp({
      name: 'Douaa Lahmar',
      email: 'douaa@flesk.com',
      matricule: 'FLK-2291',
      role: 'admin',
    });

    httpMock.expectOne(ME_URL).flush({ client_ip: '196.203.44.12' });
    renderViewer();

    const text = renderedWatermark();
    const fields = text.split('|').map(f => f.trim());

    expect(fields.length).toBe(4);
    expect(fields[0]).toBe('Douaa Lahmar');
    expect(fields[1]).toBe('FLK-2291');
    expect(fields[2]).toBe('196.203.44.12');

    // fr-FR to the second: "04/08/2026 17:56:46". `timeStyle: 'short'` would
    // stop at the minute, which §10.3 does not allow.
    expect(fields[3]).toMatch(/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2}$/);

    expect(text).not.toContain('undefined');
  });

  /** The IP arrives after first paint; the overlay must pick it up reactively. */
  it('substitutes the IP into an already-rendered watermark', () => {
    setUp({ name: 'Douaa Lahmar', email: 'douaa@flesk.com', matricule: 'FLK-2291', role: 'admin' });

    const meRequest = httpMock.expectOne(ME_URL);
    renderViewer();
    expect(renderedWatermark()).toContain('FLK-2291 | — |');

    meRequest.flush({ client_ip: '196.203.44.12' });
    fixture.detectChanges();
    expect(renderedWatermark()).toContain('FLK-2291 | 196.203.44.12 |');
  });

  /**
   * Degradation, matching the existing matricule fallback: a failed lookup
   * shows the placeholder, never the string "undefined", and never blocks the
   * document from rendering.
   */
  it('degrades to a placeholder when the IP cannot be resolved', () => {
    setUp({ name: 'Douaa Lahmar', email: 'douaa@flesk.com', matricule: 'FLK-2291', role: 'admin' });

    httpMock.expectOne(ME_URL).flush('nope', { status: 500, statusText: 'Server Error' });
    renderViewer();

    const fields = renderedWatermark().split('|').map(f => f.trim());
    expect(fields.length).toBe(4);
    expect(fields[2]).toBe('—');
    expect(renderedWatermark()).not.toContain('undefined');
  });

  /** A response with no `client_ip` key at all must degrade the same way. */
  it('degrades when the API omits client_ip entirely', () => {
    setUp({ name: 'Douaa Lahmar', email: 'douaa@flesk.com', matricule: 'FLK-2291', role: 'admin' });

    httpMock.expectOne(ME_URL).flush({ user: { id: 1 } });
    renderViewer();

    expect(renderedWatermark().split('|').map(f => f.trim())[2]).toBe('—');
    expect(renderedWatermark()).not.toContain('undefined');
  });

  // ----------------------------------------------------- §10.2 protections

  /**
   * The key matrix lives in BlockCopyShortcutsDirective's own spec; this is
   * the wiring check — that the article viewer's frame carries the guard.
   * Dispatched on `document` because that is where a real Ctrl+P lands: the
   * frame is a non-focusable div, so focus stays on <body>.
   */
  it('blocks the save and print shortcuts while a document is displayed', () => {
    setUp({ name: 'Douaa Lahmar', matricule: 'FLK-2291', role: 'admin' });
    httpMock.expectOne(ME_URL).flush({ client_ip: '10.0.0.1' });
    renderViewer();

    for (const key of ['s', 'p']) {
      const event = new KeyboardEvent('keydown', {
        key,
        ctrlKey: true,
        bubbles: true,
        cancelable: true,
      });
      document.dispatchEvent(event);

      expect(event.defaultPrevented).withContext(`Ctrl+${key} was not blocked`).toBeTrue();
    }
  });

  it('blocks right-click and text selection on the document frame', () => {
    setUp({ name: 'Douaa Lahmar', matricule: 'FLK-2291', role: 'admin' });
    httpMock.expectOne(ME_URL).flush({ client_ip: '10.0.0.1' });
    renderViewer();

    const frame: HTMLElement = fixture.nativeElement.querySelector('.ad-frame');
    const event = new MouseEvent('contextmenu', { bubbles: true, cancelable: true });
    frame.dispatchEvent(event);

    expect(event.defaultPrevented).toBeTrue();
    expect(getComputedStyle(frame).userSelect).toBe('none');
  });

  // ------------------------------------------- workflow action bar (role × status)

  describe('workflow action bar', () => {
    const AUTHOR_ID = 1;
    const SOMEONE_ELSE_ID = 99;

    /**
     * Loads the detail page as `accessRole`, showing an article in `status`.
     * The article has no attached format here — the viewer is irrelevant to
     * the action bar, and dropping it keeps each case to a single flush.
     */
    function show(
      accessRole: string | null,
      status: Article['status'],
      userId: number = SOMEONE_ELSE_ID
    ): void {
      setUp({
        id: userId,
        name: 'Testeur',
        email: 't@flesk.com',
        role: 'admin',
        access_role: accessRole,
      });

      httpMock.expectOne(ME_URL).flush({ client_ip: '10.0.0.1' });
      httpMock
        .expectOne(ARTICLE_URL)
        .flush(articleFixture({ status, author_id: AUTHOR_ID, format_pdf_drive_id: null }));
      fixture.detectChanges();
    }

    /** Which action buttons are actually in the DOM, by data-testid. */
    function visibleActions(): string[] {
      // `fixture.nativeElement` is typed `any`, which makes the generic form of
      // querySelectorAll a compile error — narrow it first.
      const host = fixture.nativeElement as HTMLElement;

      return Array.from(host.querySelectorAll<HTMLElement>('[data-testid^="action-"]')).map(
        el => el.getAttribute('data-testid')!
      );
    }

    it('offers the metier validation and reject to a responsable_departement on pending_metier', () => {
      show('responsable_departement', 'pending_metier');

      expect(visibleActions()).toEqual(['action-validate-metier', 'action-reject']);
    });

    it('offers nothing to a responsable_departement on pending_qualite (not their stage)', () => {
      show('responsable_departement', 'pending_qualite');

      expect(visibleActions()).toEqual([]);
      expect(fixture.nativeElement.querySelector('[data-testid="workflow-actions"]')).toBeNull();
    });

    it('offers publication and reject to qualite on pending_qualite', () => {
      show('qualite', 'pending_qualite');

      expect(visibleActions()).toEqual(['action-validate-qualite', 'action-reject']);
    });

    it('offers nothing to qualite on pending_metier (not their stage)', () => {
      show('qualite', 'pending_metier');

      expect(visibleActions()).toEqual([]);
    });

    it('offers submission to the redacteur who authored the draft', () => {
      show('redacteur', 'draft', AUTHOR_ID);

      expect(visibleActions()).toEqual(['action-submit']);
    });

    /**
     * ArticleController::submit() checks author_id with no role bypass, so a
     * redacteur looking at a colleague's draft must not see the button — it
     * would 403 every time.
     */
    it('hides submission from a redacteur who is not the author', () => {
      show('redacteur', 'draft', SOMEONE_ELSE_ID);

      expect(visibleActions()).toEqual([]);
    });

    it('offers nothing to a redacteur once the article has left draft', () => {
      show('redacteur', 'pending_metier', AUTHOR_ID);

      expect(visibleActions()).toEqual([]);
    });

    /** hasAccessRole() passes 'admin' for every set, so both stages are theirs. */
    it('offers the metier stage to an admin', () => {
      show('admin', 'pending_metier');

      expect(visibleActions()).toEqual(['action-validate-metier', 'action-reject']);
    });

    it('offers the qualite stage to an admin', () => {
      show('admin', 'pending_qualite');

      expect(visibleActions()).toEqual(['action-validate-qualite', 'action-reject']);
    });

    /**
     * The client asked for data_owner to have every transition, but neither
     * the validate-metier nor the validate-qualite Gate lists it, so the
     * server would 403. The bar mirrors the server rather than the request —
     * see the note in the component. Change the Gates and this expectation
     * together, or not at all.
     */
    it('offers no validation to a data_owner, matching the backend Gates', () => {
      show('data_owner', 'pending_metier');

      expect(visibleActions()).toEqual([]);
    });

    it('never offers anything to a lecteur', () => {
      for (const status of ['draft', 'pending_metier', 'pending_qualite', 'published'] as const) {
        show('lecteur', status, AUTHOR_ID);
        expect(visibleActions()).withContext(`lecteur on ${status}`).toEqual([]);
        TestBed.resetTestingModule();
      }
    });

    it('offers nothing on a published article, whatever the role', () => {
      show('qualite', 'published');

      expect(visibleActions()).toEqual([]);
    });

    /** A session cached before access_role was persisted reads as "no rights". */
    it('offers nothing when access_role is absent', () => {
      show(null, 'pending_metier');

      expect(visibleActions()).toEqual([]);
    });

    // ------------------------------------------------------------- behaviour

    it('refuses to send a rejection with no reason', () => {
      show('qualite', 'pending_qualite');

      fixture.nativeElement.querySelector('[data-testid="action-reject"]').click();
      fixture.detectChanges();
      fixture.nativeElement.querySelector('[data-testid="action-reject-confirm"]').click();
      fixture.detectChanges();

      httpMock.expectNone(`${ARTICLE_URL}/reject`);
      expect(component.actionError()).toContain('motif');
    });

    it('sends the reason and reshapes the bar from the response', () => {
      show('responsable_departement', 'pending_metier');

      fixture.nativeElement.querySelector('[data-testid="action-validate-metier"]').click();

      const request = httpMock.expectOne(`${ARTICLE_URL}/validate-metier`);
      expect(request.request.method).toBe('POST');

      // The server's updated article is what the bar re-reads: this validator
      // does not own the qualite stage, so their actions disappear.
      request.flush(articleFixture({ status: 'pending_qualite', format_pdf_drive_id: null }));
      fixture.detectChanges();

      expect(visibleActions()).toEqual([]);
      expect(component.actionSuccess()).toContain('validation qualité');
    });
  });
});
