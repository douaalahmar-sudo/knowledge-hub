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
const articleFixture = (): Article =>
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
});
